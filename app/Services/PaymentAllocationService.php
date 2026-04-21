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
                'message' => 'PA service - No payment breakdowns found for this contract.'
            ], 404);
        }
    }


    public function allocatePayments($contract_no){
        // TEST
        //Add CORS headers for testing
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

            $this->paymentBreakdownService->refreshPaymentBreakdown($contract_no);
            $this->amortizationService->getOrGenerateAmortizationSchedule($contract_no);

            $amortizationTable = MasterAmortization::where('contract_no', $contract_no)
                ->orderBy('due_date')
                ->get();

            $amortizationData = $amortizationTable->map(function ($item) {
                return [
                    'id' => $item->id,
                    'due_date' => $item->due_date,
                    'original_balance_payment' => (float) $item->payment,
                    'balance_payment' => (float) $item->balance_payment,
                    'current_interest' => (float) $item->balance_interest,
                    'current_rent' => (float) $item->balance_principal,
                    'overdue_int' => (float) $item->overdue_int,
                    'completed' => 0,
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
                    'overdue_interest' => (float) ($payment->overdue_interest ?? 0),
                    'overdue_rent' => (float) ($payment->overdue_rent ?? 0),
                    'current_interest' => (float) ($payment->current_interest ?? 0),
                    'current_rent' => (float) ($payment->current_rent ?? 0),
                    'future_rent' => (float) ($payment->future_rent ?? 0),
                    'allocated' => (float) ($payment->allocated ?? 0),
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

                // Sort by due_date using strtotime (no Carbon)
                usort($pendingRows, function($a, $b) {
                    return strtotime($a['due_date']) - strtotime($b['due_date']);
                });

                $pendingRowsList = array_values($pendingRows);
                $rowCount = count($pendingRowsList);

                    // If no pending rows, allocate to upcoming/future rows
                if ($rowCount === 0) {
                    $upcomingRows = array_filter($amortizationData, function($row) use ($paymentDate) {
                        return $row['due_date'] > $paymentDate && $row['completed'] == 0;
                    });

                    usort($upcomingRows, function($a, $b) {
                        return strtotime($a['due_date']) - strtotime($b['due_date']);
                    });

                    if (!empty($upcomingRows) && $remainingPayment > 0) {
                        // Get the first upcoming row
                        $firstUpcomingRow = reset($upcomingRows);
                        $index = array_search($firstUpcomingRow['id'], array_column($amortizationData, 'id'));

                        if ($index !== false) {
                            // Get interest and principal from amortization row
                            $currentInterest = $amortizationData[$index]['current_interest'];
                            $currentPrincipal = $amortizationData[$index]['current_rent'];
                            $totalCurrentDue = $currentInterest + $currentPrincipal;

                            // FIRST: Pay current_interest
                            if ($remainingPayment >= $currentInterest) {
                                $paymentsData[$pIndex]['current_interest'] = $currentInterest;
                                $remainingPayment -= $currentInterest;
                            } else {
                                $paymentsData[$pIndex]['current_interest'] = $remainingPayment;
                                $remainingPayment = 0;
                            }

                            // SECOND: Pay current_rent (principal) if remaining payment exists
                            if ($remainingPayment > 0) {
                                if ($remainingPayment >= $currentPrincipal) {
                                    $paymentsData[$pIndex]['current_rent'] = $currentPrincipal;
                                    $remainingPayment -= $currentPrincipal;
                                    $amortizationData[$index]['completed'] = 1;
                                    $amortizationData[$index]['balance_payment'] = 0;
                                } else {
                                    $paymentsData[$pIndex]['current_rent'] = $remainingPayment;
                                    $amortizationData[$index]['balance_payment'] -= $remainingPayment;
                                    $remainingPayment = 0;
                                }
                            }

                            // THIRD: Any remaining payment goes to future rows as future_rent
                            if ($remainingPayment > 0) {
                                // Get remaining future rows (excluding the first one we just processed)
                                $futureRows = array_filter($amortizationData, function($row) use ($firstUpcomingRow) {
                                    return $row['due_date'] > $firstUpcomingRow['due_date'] && $row['completed'] == 0;
                                });

                                usort($futureRows, function($a, $b) {
                                    return strtotime($a['due_date']) - strtotime($b['due_date']);
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
                                    } else {
                                        $paymentsData[$pIndex]['future_rent'] += $remainingPayment;
                                        $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                        $remainingPayment = 0;
                                    }
                                }
                            }
                        }
                    }
                    continue; // Move to next payment
                }

                // SINGLE ROW - Simple handling
                if ($rowCount === 1) {
                    $row = $pendingRowsList[0];
                    $index = array_search($row['id'], array_column($amortizationData, 'id'));

                    if ($index !== false) {
                        // Calculate overdue_int if payment is after amortization date (using timestamps, no Carbon)
                        $amortizationTimestamp = strtotime($row['due_date']);
                        $paymentTimestamp = strtotime($payment['payment_date']);
                        $overdue_int = 0;

                        if ($paymentTimestamp > $amortizationTimestamp) {
                            // Payment is after amortization date - calculate overdue interest
                            $daysDiff = floor(($paymentTimestamp - $amortizationTimestamp) / (60 * 60 * 24));
                            // Cast contractDefIntRate to float
                            $contractDefIntRate = (float) $data->def_int_rate;

                            // Then use it in calculation
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
                            //var_dump($paymentsData[$pIndex]);
                            // Find future rows (not yet due and incomplete)
                            $futureRows = array_filter($amortizationData, function($row) use ($paymentDate) {
                                return $row['due_date'] > $paymentDate && $row['completed'] == 0;
                            });

                            // Sort future rows by due_date using strtotime (no Carbon)
                            usort($futureRows, function($a, $b) {
                                return strtotime($a['due_date']) - strtotime($b['due_date']);
                            });

                            foreach ($futureRows as $futureRow) {
                                if ($remainingPayment <= 0) break;

                                $futureIndex = array_search($futureRow['id'], array_column($amortizationData, 'id'));
                                if ($futureIndex === false) continue;

                                $futureAmountDue = $amortizationData[$futureIndex]['balance_payment'];

                                if ($remainingPayment >= $futureAmountDue) {
                                    $paymentsData[$pIndex]['future_rent'] = $futureAmountDue;
                                    $remainingPayment -= $futureAmountDue;
                                    $amortizationData[$futureIndex]['completed'] = 1;
                                    $amortizationData[$futureIndex]['balance_payment'] = 0;
                                } else {

                                    $paymentsData[$pIndex]['future_rent'] = $remainingPayment;
                                    $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                    $remainingPayment = 0;
                                }
                            }
                        }
                    }
                }

                // MULTIPLE ROWS - Complex handling with days diff calculation
                else {
                    // Step 1: Calculate overdue_int for each row based on days diff to next row

                    // First, calculate sum of all balance_payments
                    $totalBalanceSum = 0;
                    foreach ($pendingRowsList as $row) {
                        $rowIndex = array_search($row['id'], array_column($amortizationData, 'id'));
                        if ($rowIndex !== false) {
                            $totalBalanceSum += $amortizationData[$rowIndex]['balance_payment'];
                        }
                    }


                    for ($i = 0; $i < $rowCount; $i++) {
                        $currentRow = $pendingRowsList[$i];
                        $nextRow = $pendingRowsList[$i + 1] ?? null;

                        $index = array_search($currentRow['id'], array_column($amortizationData, 'id'));
                        if ($index === false) continue;

                        if ($nextRow) {
                            // Calculate days diff between current row and NEXT row

                            // Use lastPaidDate if available and more recent than current due date
                            $startTimestamp = strtotime($currentRow['due_date']);
                            if(isset($lastPaidDate)){
                                $lastPaidTimestamp = strtotime($lastPaidDate);
                            }

                            // If last paid date is after current due date, use last paid date as start
                            if (isset($lastPaidDate) && $lastPaidTimestamp > $startTimestamp) {
                                $startTimestamp = $lastPaidTimestamp;
                            }

                            $nextTimestamp = strtotime($nextRow['due_date']);
                            $daysDiff = floor(($nextTimestamp - $startTimestamp) / (60 * 60 * 24));

                            $overdue_int = ($contractDefIntRate * $daysDiff * $currentRow['balance_payment']) / 100;

                            // Save overdue_int to amortization
                            $amortizationData[$index]['overdue_int'] = $overdue_int;


                            // Add overdue_int to balance_payment
                            //$amortizationData[$index]['balance_payment'] += $overdue_int;
                        } else {
                            // LAST ROW - Use total sum of all balance_payments
                            $currentTimestamp = strtotime($currentRow['due_date']);
                            $paymentTimestamp = strtotime($paymentDate);
                            $daysDiff = floor(($paymentTimestamp - $currentTimestamp) / (60 * 60 * 24));

                            if ($daysDiff > 0) {
                                // Use TOTAL sum for calculation
                                $overdue_int = ($contractDefIntRate * $daysDiff * $totalBalanceSum) / 100;

                                // Save overdue_int to amortization
                                $amortizationData[$index]['overdue_int'] = $overdue_int;

                                // Add overdue_int to last row's balance_payment
                                //$amortizationData[$index]['balance_payment'] = $overdue_int;
                            }
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
                    if ($remainingPayment > 0) {

                    //echo ' $remainingPayment  ' . $remainingPayment;

                        foreach ($pendingRowsList as $row) {
                            if ($remainingPayment <= 0) break;

                            $index = array_search($row['id'], array_column($amortizationData, 'id'));
                            if ($index === false) continue;

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
                    }

                    // THIRD: Any remaining payment becomes future_rent - apply to future rows
                    if ($remainingPayment > 0) {
                        $futureRows = array_filter($amortizationData, function($row) use ($paymentDate) {
                            return $row['due_date'] > $paymentDate && $row['completed'] == 0;
                        });

                        usort($futureRows, function($a, $b) {
                            return strtotime($a['due_date']) - strtotime($b['due_date']);
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
                            } else {
                                $paymentsData[$pIndex]['future_rent'] += $remainingPayment;
                                $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                $remainingPayment = 0;
                            }
                        }
                    }
                }

                $lastPaidDate = $paymentDate;
            }

            // Update master_amortization table
            $caseBalance = [];
            $caseOverDueInt = [];
            $caseCompleted = [];
            $dueDates = [];

            foreach ($amortizationData as $row) {
                $dueDate = $row['due_date'];
                $caseBalance[] = "WHEN due_date = '{$dueDate}' THEN {$row['balance_payment']}";
                $caseOverDueInt[] = "WHEN due_date = '{$dueDate}' THEN {$row['overdue_int']}";
                $caseCompleted[] = "WHEN due_date = '{$dueDate}' THEN {$row['completed']}";
                $dueDates[] = "'{$dueDate}'";
            }

            if (!empty($caseBalance)) {
                DB::update("
                    UPDATE master_amortization
                    SET
                        balance_payment = CASE " . implode(' ', $caseBalance) . " END,
                        overdue_int = CASE " . implode(' ', $caseOverDueInt) . " END,
                        completed = CASE " . implode(' ', $caseCompleted) . " END
                    WHERE
                        contract_no = ? AND
                        due_date IN (" . implode(',', $dueDates) . ")
                ", [$contract_no]);
            }

                // Update payment_breakdowns table
            $caseCurrentInterest = [];
            $caseCurrentRent = [];
            $caseOverDueInterest = [];
            $caseOverDueRent = [];
            $caseFutureRent = [];
            $ids = [];

            foreach ($paymentsData as $data) {
                $currentInterest = $data['current_interest'] ?? 0;
                $currentRent = $data['current_rent'] ?? 0;
                $overDueInterest = $data['overdue_interest'] ?? 0;
                $overDueRent = $data['overdue_rent'] ?? 0;
                $futureRent = $data['future_rent'] ?? 0;
                $id = $data['pymnt_id'];

                $caseCurrentInterest[] = "WHEN pymnt_id = '{$id}' THEN {$currentInterest}";
                $caseCurrentRent[] = "WHEN pymnt_id = '{$id}' THEN {$currentRent}";
                $caseOverDueInterest[] = "WHEN pymnt_id = '{$id}' THEN {$overDueInterest}";
                $caseOverDueRent[] = "WHEN pymnt_id = '{$id}' THEN {$overDueRent}";
                $caseFutureRent[] = "WHEN pymnt_id = '{$id}' THEN {$futureRent}";
                $ids[] = "'{$id}'";
            }

            if (!empty($caseCurrentInterest)) {
                DB::update("
                    UPDATE payment_breakdowns
                    SET
                        current_interest = CASE " . implode(' ', $caseCurrentInterest) . " END,
                        current_rent = CASE " . implode(' ', $caseCurrentRent) . " END,
                        overdue_interest = CASE " . implode(' ', $caseOverDueInterest) . " END,
                        overdue_rent = CASE " . implode(' ', $caseOverDueRent) . " END,
                        future_rent = CASE " . implode(' ', $caseFutureRent) . " END
                    WHERE pymnt_id IN (" . implode(',', $ids) . ")
                ");
            }

            return response()->json([
                'success' => true,
                'message' => 'Payments allocated successfully for contract ' . $contract_no
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to allocate payments for contract ' . $contract_no . ': ' . $e->getMessage()
            ], 500);
        }



    }

}
