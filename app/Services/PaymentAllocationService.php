<?php

namespace App\Services;
use App\Models\PaymentBreakdown;
use App\Models\MasterAmortization;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use App\Services\AmortizationService;
use App\Services\PaymentBreakdownService;
use Carbon\Carbon;



class PaymentAllocationService
{
    protected $amortizationService;
    protected $paymentBreakdownService;

    public function __construct(
        AmortizationService $amortizationService,
        PaymentBreakdownService $paymentBreakdownService
    ) {
        $this->amortizationService = $amortizationService;
        $this->paymentBreakdownService = $paymentBreakdownService;
    }

    /**
     * Truncate all data before batch processing
    */
    public function truncateAllData()
    {
        // Disable foreign key checks temporarily if needed
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        PaymentBreakdown::truncate();
        MasterAmortization::truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function getContractDetails($contract_no): JsonResponse
    {
        $contractDetails = Contract::where('contract_no', $contract_no)->first();

        if (!$contractDetails) {
            return response()->json([
                'completed' => 404,
                'message' => 'Contract not found'
            ], 404);
        }

        return response()->json([
            'completed' => 200,
            'contract' => $contractDetails,
            'contract_no' => $contractDetails->contract_no,
            'def_int_rate' => $contractDetails->def_int_rate,
            'execution_date' => $contractDetails->loan_execution_date,
        ], 200);
    }

    public function getAmortizationSchedule($contract): JsonResponse
    {
        $amortizationSchedule = $this->amortizationService->getOrGenerateAmortizationSchedule($contract);

        if ($amortizationSchedule) {
            return response()->json([
                'completed' => 200,
                'message' => 'Successfully generated amortization schedule',
            ], 200);
        }

        return response()->json([
            'completed' => 404,
            'message' => 'Unable to generate amortization schedule',
        ], 404);
    }

    public function refreshPaymentBreakdown($contract_no): JsonResponse
    {
        $paymentBreakdowns = $this->paymentBreakdownService->refreshPaymentBreakdown($contract_no);

        if ($paymentBreakdowns) {
            return response()->json([
                'completed' => 200,
                'breakdown' => $paymentBreakdowns,
            ], 200);
        }

        return response()->json([
            'completed' => 404,
            'message' => 'Unable to refresh payment breakdown',
        ], 404);
    }

    public function getPaymentBreakdown($contract_no){
        $paymentBreakdown = PaymentBreakdown::where('contract_no', $contract_no)->get();

        if ($paymentBreakdown->isNotEmpty()) {
            return $paymentBreakdown;
        } else {
            return response()->json([
                'completed' => 404,
                'message' => 'No payment breakdowns found for this contract.'
            ], 404);
        }
    }


    public function allocatePayments($contract_no){
    // Add CORS headers for testing
    // header('Access-Control-Allow-Origin: http://localhost:5173');
    // header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    // header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        try {
            $contractDetails = $this->getContractDetails($contract_no);

            // Decode the JSON content
            $data = $contractDetails->getData();

            // Access the def_int_rate value
            $contractDefIntRate = $data->def_int_rate;
            $contrExecutionDate = $data->execution_date;


            PaymentBreakdown::truncate();
            MasterAmortization::truncate();

            // Delete records for specific contract instead of truncating
            //PaymentBreakdown::where('contract_no', $contract_no)->delete();
            //MasterAmortization::where('contract_no', $contract_no)->delete();

            $this->paymentBreakdownService->refreshPaymentBreakdown($contract_no);
            $this->amortizationService->getOrGenerateAmortizationSchedule($contract_no);


            $amortizationTable = MasterAmortization::where('contract_no', $contract_no)
            ->orderBy('due_date')
            ->get();

            $amortizationData = $amortizationTable->map(function ($item) {
                return [
                    'id' => $item->id,
                    'due_date' => $item->due_date,
                    'original_balance_payment' => (float) $item->payment, // Will be modified
                    'balance_payment' => (float) $item->balance_payment, // Will be modified
                    'current_interest' => (float) $item->balance_interest,
                    'current_rent' => (float) $item->balance_principal,
                    'overdue_int' => (float) $item->overdue_int,
                    'completed' => 0, // 0 = incomplete, 1 = complete
                ];
            })->toArray();

            $payments = PaymentBreakdown::where('contract_no', $contract_no)
            ->orderBy('payment_date')
            ->get();

            $paymentsData = $payments->map(function ($payment) {
                return [
                    'pymnt_id' => $payment->pymnt_id,
                    'payment_date' => $payment->payment_date,
                    'payment_amount' => (float) $payment->payment_amount,
                    'overdue_interest' => (float) $payment->overdue_interest ?? 0,
                    'overdue_rent' => (float) $payment->overdue_rent ?? 0,
                    'current_interest' => (float) $payment->current_interest ?? 0,
                    'current_rent' => (float) $payment->current_rent ?? 0,
                    'future_rent' => (float) $payment->future_rent ?? 0,
                    'allocated' => $payment->allocated ?? 0, // Assuming 'allocated' is a field in the PaymentBreakdown model
                ];
            })->toArray();


            foreach ($paymentsData as $pIndex => $payment) {
                // Initialize payment allocation for this payment
                $paymentsData[$pIndex]['overdue_interest'] = 0;
                $paymentsData[$pIndex]['overdue_rent'] = 0;
                $paymentsData[$pIndex]['future_rent'] = 0;

                $remainingPayment = $payment['payment_amount'];
                $paymentDate = $payment['payment_date'];

                // Get all incomplete rows up to payment date, ordered by due_date
                $pendingRows = array_filter($amortizationData, function($row) use ($paymentDate) {
                    return $row['due_date'] <= $paymentDate && $row['completed'] == 0;
                });

                // Sort by due_date using Carbon
                usort($pendingRows, function($a, $b) {
                    return Carbon::parse($a['due_date'])->gte(Carbon::parse($b['due_date']));
                });

                $pendingRowsList = array_values($pendingRows);
                $rowCount = count($pendingRowsList);

                if ($rowCount === 0) {
                    // No overdue rows, check if payment can be applied to future rows
                    $futureRows = array_filter($amortizationData, function($row) {
                        return $row['completed'] == 0;
                    });

                    if (!empty($futureRows) && $remainingPayment > 0) {
                        // Apply directly to future rows
                        foreach ($futureRows as $futureRow) {
                            if ($remainingPayment <= 0) break;

                            $index = array_search($futureRow['id'], array_column($amortizationData, 'id'));
                            if ($index === false) continue;

                            $amountDue = $amortizationData[$index]['balance_payment'];

                            if ($remainingPayment >= $amountDue) {
                                $paymentsData[$pIndex]['future_rent'] += $amountDue;
                                $remainingPayment -= $amountDue;
                                $amortizationData[$index]['completed'] = 1;
                                $amortizationData[$index]['balance_payment'] = 0;
                            } else {
                                $paymentsData[$pIndex]['future_rent'] += $remainingPayment;
                                $amortizationData[$index]['balance_payment'] -= $remainingPayment;
                                $remainingPayment = 0;
                            }
                        }
                    }
                    continue;
                }

                // SINGLE ROW - Simple handling
                if ($rowCount === 1) {
                    $row = $pendingRowsList[0];
                    $index = array_search($row['id'], array_column($amortizationData, 'id'));

                    if ($index !== false) {
                        // Calculate overdue_int if payment is after amortization date
                        $amortizationDate = Carbon::parse($row['due_date']);
                        $paymentDateObj = Carbon::parse($payment['payment_date']);
                        $overdue_int = 0;

                        if ($paymentDateObj->gt($amortizationDate)) {
                            // Payment is after amortization date - calculate overdue interest
                            $daysDiff = $amortizationDate->diffInDays($paymentDateObj);
                            $overdue_int = ($daysDiff * $contractDefIntRate * $amortizationData[$index]['balance_payment']) / 100;

                            // Save overdue_int to amortization
                            $amortizationData[$index]['overdue_int'] += $overdue_int;
                        }

                        // FIRST: Pay overdue_interest from the payment
                        if ($remainingPayment >= $overdue_int) {
                            $paymentsData[$pIndex]['overdue_interest'] = $overdue_int;
                            $remainingPayment -= $overdue_int;
                        } else {
                            $paymentsData[$pIndex]['overdue_interest'] = $remainingPayment;
                            $remainingPayment = 0;
                        }

                        // SECOND: Apply remaining payment to overdue_rent (principal)
                        if ($remainingPayment > 0) {
                            $amountDue = $amortizationData[$index]['balance_payment'];

                            if ($remainingPayment >= $amountDue) {
                                $paymentsData[$pIndex]['overdue_rent'] += $amountDue;
                                $remainingPayment -= $amountDue;
                                $amortizationData[$index]['completed'] = 1;
                                $amortizationData[$index]['balance_payment'] = 0;
                            } else {
                                $paymentsData[$pIndex]['overdue_rent'] += $remainingPayment;
                                $amortizationData[$index]['balance_payment'] -= $remainingPayment;
                                $remainingPayment = 0;
                            }
                        }

                        // THIRD: Any remaining payment becomes future_rent - apply to future rows
                        if ($remainingPayment > 0) {
                            // Find future rows (not yet due and incomplete)
                            $futureRows = array_filter($amortizationData, function($row) use ($paymentDate) {
                                return $row['due_date'] > $paymentDate && $row['completed'] == 0;
                            });

                            // Sort future rows by due_date
                            usort($futureRows, function($a, $b) {
                                return Carbon::parse($a['due_date'])->gte(Carbon::parse($b['due_date']));
                            });

                            foreach ($futureRows as $futureRow) {
                                if ($remainingPayment <= 0) break;

                                $futureIndex = array_search($futureRow['id'], array_column($amortizationData, 'id'));
                                if ($futureIndex === false) continue;

                                $futureAmountDue = $amortizationData[$futureIndex]['balance_payment'];

                                if ($remainingPayment >= $futureAmountDue) {
                                    $paymentsData[$pIndex]['future_rent'] += $futureAmountDue;
                                    $remainingPayment -= $futureAmountDue;
                                    $amortizationData[$futureIndex]['completed'] = 1;
                                    $amortizationData[$futureIndex]['balance_payment'] = 0;
                                    var_dump("Future rent applied to row ID: {$futureRow['id']} - fully paid");
                                } else {
                                    $paymentsData[$pIndex]['future_rent'] += $remainingPayment;
                                    $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                    $remainingPayment = 0;
                                    var_dump("Future rent applied to row ID: {$futureRow['id']} - partially paid, remaining balance: {$amortizationData[$futureIndex]['balance_payment']}");
                                }
                            }
                        }
                    }
                }

            // MULTIPLE ROWS - Complex handling with days diff calculation
            else {
                // Step 1: Calculate overdue_int for each row based on days diff to next row
                // AND add it to balance_payment
                for ($i = 0; $i < $rowCount; $i++) {
                    $currentRow = $pendingRowsList[$i];
                    $nextRow = $pendingRowsList[$i + 1] ?? null;

                    $index = array_search($currentRow['id'], array_column($amortizationData, 'id'));
                    if ($index === false) continue;

                    if ($nextRow) {
                        $currentDate = Carbon::parse($currentRow['due_date']);
                        $nextDate = Carbon::parse($nextRow['due_date']);
                        $daysDiff = $currentDate->diffInDays($nextDate);

                        $overdue_int = ($contractDefIntRate * $daysDiff * $currentRow['balance_payment']) / 100;

                        // Save overdue_int to amortization
                        $amortizationData[$index]['overdue_int'] = $overdue_int;

                        // IMPORTANT: Add overdue_int to balance_payment
                        $amortizationData[$index]['balance_payment'] += $overdue_int;
                    }
                }

                // Calculate total overdue interest that needs to be paid
                $totalOverdueInterest = 0;
                foreach ($pendingRowsList as $row) {
                    $index = array_search($row['id'], array_column($amortizationData, 'id'));
                    if ($index !== false) {
                        $totalOverdueInterest += $amortizationData[$index]['overdue_int'];
                    }
                }

                // FIRST: Pay overdue_interest from the payment
                $paidInterest = 0;
                if ($remainingPayment >= $totalOverdueInterest) {
                    $paidInterest = $totalOverdueInterest;
                    $remainingPayment -= $totalOverdueInterest;
                } else {
                    $paidInterest = $remainingPayment;
                    $remainingPayment = 0;
                }
                $paymentsData[$pIndex]['overdue_interest'] = $paidInterest;

                // SECOND: Apply remaining payment to overdue_rent (principal) for each row
                // Note: The principal now includes the overdue_int that was added
                if ($remainingPayment > 0) {
                    foreach ($pendingRowsList as $row) {
                        if ($remainingPayment <= 0) break;

                        $index = array_search($row['id'], array_column($amortizationData, 'id'));
                        if ($index === false) continue;

                        $amountDue = $amortizationData[$index]['balance_payment'];

                        if ($remainingPayment >= $amountDue) {
                            // Full payment
                            $paymentsData[$pIndex]['overdue_rent'] += $amountDue;
                            $remainingPayment -= $amountDue;
                            $amortizationData[$index]['completed'] = 1;
                            $amortizationData[$index]['balance_payment'] = 0;
                            var_dump("Row {$row['id']} - fully paid with amount: {$amountDue}");
                        } else {
                            // Partial payment
                            $paymentsData[$pIndex]['overdue_rent'] += $remainingPayment;
                            $amortizationData[$index]['balance_payment'] -= $remainingPayment;
                            var_dump("Row {$row['id']} - partially paid: {$remainingPayment}, new balance: {$amortizationData[$index]['balance_payment']}");
                            $remainingPayment = 0;
                        }
                    }
                }

                // THIRD: Any remaining payment becomes future_rent - apply to future rows
                if ($remainingPayment > 0) {
                    $futureRows = array_filter($amortizationData, function($row) use ($paymentDate) {
                        return $row['due_date'] > $paymentDate && $row['completed'] == 0;
                    });

                    usort($futureRows, function($a, $b) {
                        return Carbon::parse($a['due_date'])->gte(Carbon::parse($b['due_date']));
                    });

                    foreach ($futureRows as $futureRow) {
                        if ($remainingPayment <= 0) break;

                        $futureIndex = array_search($futureRow['id'], array_column($amortizationData, 'id'));
                        if ($futureIndex === false) continue;

                        $futureAmountDue = $amortizationData[$futureIndex]['balance_payment'];

                        if ($remainingPayment >= $futureAmountDue) {
                            $paymentsData[$pIndex]['future_rent'] += $futureAmountDue;
                            $remainingPayment -= $futureAmountDue;
                            $amortizationData[$futureIndex]['completed'] = 1;
                            $amortizationData[$futureIndex]['balance_payment'] = 0;
                            var_dump("Future rent fully applied to row ID: {$futureRow['id']}");
                        } else {
                            $paymentsData[$pIndex]['future_rent'] += $remainingPayment;
                            $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                            $remainingPayment = 0;
                            var_dump("Future rent partially applied to row ID: {$futureRow['id']}, remaining balance: {$amortizationData[$futureIndex]['balance_payment']}");
                        }
                    }
                }
            }

                // Debug: Verify total equals original payment
                $totalAllocated = $paymentsData[$pIndex]['overdue_interest'] +
                                $paymentsData[$pIndex]['overdue_rent'] +
                                $paymentsData[$pIndex]['future_rent'];

                var_dump("Payment {$payment['pymnt_id']}: Total allocated {$totalAllocated} of {$payment['payment_amount']}");
            }



            $caseBalance = [];
            $caseOverDueInt = [];
            $caseCompleted = [];
            $dueDates = [];

            foreach ($amortizationData as $row) {
                //var_dump($row);
                $dueDate = $row['due_date'];
                $caseBalance[] = "WHEN due_date = '{$dueDate}' THEN {$row['balance_payment']}";
                $caseOverDueInt[] = "WHEN due_date = '{$dueDate}' THEN {$row['overdue_int']}";
                $caseCompleted[] = "WHEN due_date = '{$dueDate}' THEN {$row['completed']}";
                $dueDates[] = "'{$dueDate}'";
            }
            unset($row); // Unset the last element to avoid duplicate key error

            DB::update("
                UPDATE  master_amortization
                SET
                    balance_payment = CASE ".implode(' ', $caseBalance)." END,
                    overdue_int = CASE ".implode(' ', $caseOverDueInt)." END,
                    completed = CASE ".implode(' ', $caseCompleted)." END
                WHERE
                    contract_no = ? AND
                    due_date IN (".implode(',', $dueDates).")
            ", [$contract_no]);


            // Update payment breakdowns
            $caseInterest = [];
            $caseRent = [];
            $caseOverDueInterest = [];
            $caseOverDueRent = [];
            $caseFutureRent = [];
            $caseAllocated = [];
            $ids = [];


            foreach ($paymentsData as $data) {
                $overDueInterest = $data['overdue_interest'] ?? 0; // Default to 0 if not set
                $overDueRent = $data['overdue_rent'] ?? 0; // Default to 0 if not set
                $interest = $data['current_interest'] ?? 0; // Default to 0 if not set
                $rent = $data['current_rent'] ?? 0; // Default to 0 if not set
                $futureRent = $data['future_rent'] ?? 0; // Default to 0 if not set
                $allocated = $data['allocated'] ?? 0; // Default to 0 if not set

                $id = $data['pymnt_id'];
                $caseOverDueInterest[] = "WHEN pymnt_id = '{$id}' THEN {$overDueInterest}";
                $caseOverDueRent[] = "WHEN pymnt_id = '{$id}' THEN {$overDueRent}";
                $caseInterest[] = "WHEN pymnt_id = '{$id}' THEN {$interest}";
                $caseRent[] = "WHEN pymnt_id = '{$id}' THEN {$rent}";
                $caseFutureRent[] = "WHEN pymnt_id = '{$id}' THEN {$futureRent}";
                $caseAllocated[] = "WHEN pymnt_id = '{$id}' THEN {$allocated}";
                $ids[] = "'{$id}'";
            }
            unset($data); // Unset the last element to avoid duplicate key error

            DB::update("
                UPDATE payment_breakdowns
                SET
                    current_interest = CASE ".implode(' ', $caseInterest)." END,
                    current_rent = CASE ".implode(' ', $caseRent)." END,
                    overdue_interest = CASE ".implode(' ', $caseOverDueInterest)." END,
                    overdue_rent = CASE ".implode(' ', $caseOverDueRent)." END,
                    future_rent = CASE ".implode(' ', $caseFutureRent)." END,
                    allocated = CASE ".implode(' ', $caseAllocated)." END
                WHERE pymnt_id IN (".implode(',', $ids).")
            ");

        } catch (\Exception $e) {
            // Log error and skip
            return "Failed to allocate payments for contract {$contract_no}: " . $e->getMessage();
        }
    }

}
