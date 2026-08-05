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
        try {
            $contractDetails = $this->getContractDetails($contract_no);
            $data = $contractDetails->getData();
            $contractDefIntRate = $data->def_int_rate;
            $contrExecutionDate = $data->execution_date;

            PaymentBreakdown::where('contract_no', $contract_no)->delete();
            MasterAmortization::where('contract_no', $contract_no)->delete();

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
                    'future_interest' => (float) ($payment->future_interest ?? 0),
                    'future_principal' => (float) ($payment->future_principal ?? 0),
                    'excess' => (float) ($payment->excess ?? 0),
                    'allocated' => (float) ($payment->allocated ?? 0),
                ];
            })->toArray();

            foreach ($paymentsData as $pIndex => $payment) {
                // Initialize payment allocation for this payment
                $paymentsData[$pIndex]['overdue_interest'] = 0;
                $paymentsData[$pIndex]['overdue_rent'] = 0;
                $paymentsData[$pIndex]['future_rent'] = 0;
                $paymentsData[$pIndex]['future_interest'] = 0;
                $paymentsData[$pIndex]['future_principal'] = 0;
                $paymentsData[$pIndex]['excess'] = 0;

                $remainingPayment = $payment['payment_amount'];
                $paymentDate = $payment['payment_date'];

                // Get all incomplete rows up to payment date, ordered by due_date
                $pendingRows = array_filter($amortizationData, function($row) use ($paymentDate) {
                    return $row['due_date'] <= $paymentDate && $row['completed'] == 0;
                });

                usort($pendingRows, function($a, $b) {
                    return strtotime($a['due_date']) - strtotime($b['due_date']);
                });

                $pendingRowsList = array_values($pendingRows);
                $rowCount = count($pendingRowsList);


                // If no pending rows, allocate to the first upcoming row
                if ($rowCount === 0) {
                    $futureRows = array_filter($amortizationData, function($row) use ($paymentDate) {
                        return $row['due_date'] > $paymentDate && $row['completed'] == 0;
                    });

                    usort($futureRows, function($a, $b) {
                        return strtotime($a['due_date']) - strtotime($b['due_date']);
                    });

                    if (!empty($futureRows) && $remainingPayment > 0) {
                        $firstRow = reset($futureRows);
                        $index = array_search($firstRow['id'], array_column($amortizationData, 'id'));

                        if ($index !== false) {
                            // Pay current_interest
                            $interest = $amortizationData[$index]['current_interest'];
                            if ($remainingPayment >= $interest) {
                                $paymentsData[$pIndex]['current_interest'] = $interest;
                                $remainingPayment -= $interest;
                            } else {
                                $paymentsData[$pIndex]['current_interest'] = $remainingPayment;
                                $remainingPayment = 0;
                            }

                            // Pay current_rent (principal)
                            if ($remainingPayment > 0) {
                                $principal = $amortizationData[$index]['current_rent'];
                                if ($remainingPayment >= $principal) {
                                    $paymentsData[$pIndex]['current_rent'] = $principal;
                                    $remainingPayment -= $principal;
                                    $amortizationData[$index]['completed'] = 1;
                                    $amortizationData[$index]['balance_payment'] = 0;
                                } else {
                                    $paymentsData[$pIndex]['current_rent'] = $remainingPayment;
                                    $amortizationData[$index]['balance_payment'] -= $remainingPayment;
                                    $remainingPayment = 0;
                                }
                            }

                            // Any remaining becomes future_rent and deduct from future rows
                            if ($remainingPayment > 0) {
                                $remainingFutureRent = $remainingPayment;
                                $remainingFutureRows = array_filter($amortizationData, function($row) use ($firstRow) {
                                    return $row['due_date'] > $firstRow['due_date'] && $row['completed'] == 0;
                                });

                                usort($remainingFutureRows, function($a, $b) {
                                    return strtotime($a['due_date']) - strtotime($b['due_date']);
                                });

                                foreach ($remainingFutureRows as $futureRow) {
                                    if ($remainingFutureRent <= 0) break;

                                    $futureIndex = array_search($futureRow['id'], array_column($amortizationData, 'id'));
                                    if ($futureIndex === false) continue;

                                    $futureInterestAmount = $amortizationData[$futureIndex]['current_interest'];
                                    $futurePrincipalAmount = $amortizationData[$futureIndex]['current_rent'];
                                    $futureTotalAmountDue = $futureInterestAmount + $futurePrincipalAmount;

                                    if ($remainingFutureRent >= $futureTotalAmountDue) {
                                        $paymentsData[$pIndex]['future_interest'] += $futureInterestAmount;
                                        $paymentsData[$pIndex]['future_principal'] += $futurePrincipalAmount;
                                        $paymentsData[$pIndex]['future_rent'] += $futureTotalAmountDue;
                                        $remainingFutureRent -= $futureTotalAmountDue;
                                        $amortizationData[$futureIndex]['completed'] = 1;
                                        $amortizationData[$futureIndex]['balance_payment'] = 0;
                                        $amortizationData[$futureIndex]['current_interest'] = 0;
                                        $amortizationData[$futureIndex]['current_rent'] = 0;
                                    } else {
                                        if ($remainingFutureRent >= $futureInterestAmount) {
                                            $paymentsData[$pIndex]['future_interest'] += $futureInterestAmount;
                                            $remainingFutureRent -= $futureInterestAmount;
                                            $amortizationData[$futureIndex]['current_interest'] = 0;

                                            $paymentsData[$pIndex]['future_principal'] += $remainingFutureRent;
                                            $paymentsData[$pIndex]['future_rent'] += $futureInterestAmount + $remainingFutureRent;
                                            $amortizationData[$futureIndex]['current_rent'] -= $remainingFutureRent;
                                            $amortizationData[$futureIndex]['balance_payment'] -= $remainingFutureRent;
                                            $remainingFutureRent = 0;
                                        } else {
                                            $paymentsData[$pIndex]['future_interest'] += $remainingFutureRent;
                                            $paymentsData[$pIndex]['future_rent'] += $remainingFutureRent;
                                            $amortizationData[$futureIndex]['current_interest'] -= $remainingFutureRent;
                                            $amortizationData[$futureIndex]['balance_payment'] -= $remainingFutureRent;
                                            $remainingFutureRent = 0;
                                        }
                                        break;
                                    }
                                }

                                if ($remainingFutureRent > 0) {
                                    $paymentsData[$pIndex]['excess'] = $remainingFutureRent;
                                }
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
                        $amortizationTimestamp = strtotime($row['due_date']);
                        $paymentTimestamp = strtotime($payment['payment_date']);
                        $overdue_int = 0;

                        // Check if payment is on-time or early
                        $isOnTime = ($paymentTimestamp <= $amortizationTimestamp);

                        if ($paymentTimestamp > $amortizationTimestamp) {
                            $daysDiff = floor(($paymentTimestamp - $amortizationTimestamp) / (60 * 60 * 24));
                            $contractDefIntRate = (float) $data->def_int_rate;
                            $overdue_int = ($daysDiff * $contractDefIntRate * $amortizationData[$index]['balance_payment']) / 100;
                            $amortizationData[$index]['overdue_int'] += $overdue_int;
                        }

                        if ($isOnTime) {
                            // ON-TIME PAYMENT: Apply to current interest and current rent
                            $currentInterestAmount = $amortizationData[$index]['current_interest'];
                            $currentRentAmount = $amortizationData[$index]['current_rent'];
                            $totalCurrentDue = $currentInterestAmount + $currentRentAmount;

                            if ($remainingPayment >= $totalCurrentDue) {
                                // Full payment of current month
                                $paymentsData[$pIndex]['current_interest'] = $currentInterestAmount;
                                $paymentsData[$pIndex]['current_rent'] = $currentRentAmount;
                                $remainingPayment -= $totalCurrentDue;
                                $amortizationData[$index]['completed'] = 1;
                                $amortizationData[$index]['balance_payment'] = 0;
                                $amortizationData[$index]['current_interest'] = 0;
                                $amortizationData[$index]['current_rent'] = 0;
                            } else {
                                // Partial payment of current month
                                if ($remainingPayment >= $currentInterestAmount) {
                                    $paymentsData[$pIndex]['current_interest'] = $currentInterestAmount;
                                    $remainingPayment -= $currentInterestAmount;
                                    $paymentsData[$pIndex]['current_rent'] = $remainingPayment;
                                    $amortizationData[$index]['current_interest'] = 0;
                                    $amortizationData[$index]['current_rent'] -= $remainingPayment;
                                    $amortizationData[$index]['balance_payment'] -= ($currentInterestAmount + $remainingPayment);
                                    $remainingPayment = 0;
                                } else {
                                    $paymentsData[$pIndex]['current_interest'] = $remainingPayment;
                                    $amortizationData[$index]['current_interest'] -= $remainingPayment;
                                    $amortizationData[$index]['balance_payment'] -= $remainingPayment;
                                    $remainingPayment = 0;
                                }
                            }

                            // Any remaining payment after current month goes to future months
                            if ($remainingPayment > 0) {
                                // Apply to future rows (same logic as your existing future rows code)
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

                                    $futureInterestAmount = $amortizationData[$futureIndex]['current_interest'];
                                    $futurePrincipalAmount = $amortizationData[$futureIndex]['current_rent'];
                                    $futureTotalAmountDue = $futureInterestAmount + $futurePrincipalAmount;

                                    if ($remainingPayment >= $futureTotalAmountDue) {
                                        $paymentsData[$pIndex]['future_interest'] += $futureInterestAmount;
                                        $paymentsData[$pIndex]['future_principal'] += $futurePrincipalAmount;
                                        $paymentsData[$pIndex]['future_rent'] += $futureTotalAmountDue;
                                        $remainingPayment -= $futureTotalAmountDue;
                                        $amortizationData[$futureIndex]['completed'] = 1;
                                        $amortizationData[$futureIndex]['balance_payment'] = 0;
                                        $amortizationData[$futureIndex]['current_interest'] = 0;
                                        $amortizationData[$futureIndex]['current_rent'] = 0;
                                    } else {
                                        if ($remainingPayment >= $futureInterestAmount) {
                                            $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                            $paymentsData[$pIndex]['future_interest'] += $futureInterestAmount;
                                            $remainingPayment -= $futureInterestAmount;
                                            $amortizationData[$futureIndex]['current_interest'] = 0;

                                            $paymentsData[$pIndex]['future_principal'] += $remainingPayment;
                                            $paymentsData[$pIndex]['future_rent'] += $futureInterestAmount + $remainingPayment;
                                            $amortizationData[$futureIndex]['current_rent'] -= $remainingPayment;
                                            $remainingPayment = 0;
                                        } else {
                                            $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                            $paymentsData[$pIndex]['future_interest'] += $remainingPayment;
                                            $paymentsData[$pIndex]['future_rent'] += $remainingPayment;
                                            $amortizationData[$futureIndex]['current_interest'] -= $remainingPayment;
                                            $remainingPayment = 0;
                                        }
                                        break;
                                    }
                                }
                            }

                            // Handle excess payment
                            if ($remainingPayment > 0) {
                                $paymentsData[$pIndex]['excess'] = $remainingPayment;
                                $remainingPayment = 0;
                            }

                        } else {
                            // OVERDUE PAYMENT: Original logic for overdue payments
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
                                    $paymentsData[$pIndex]['overdue_cur_principal'] = $amortizationData[$index]['current_rent'];
                                    $paymentsData[$pIndex]['overdue_cur_interest'] = $amortizationData[$index]['current_interest'];
                                } else {
                                    $paymentsData[$pIndex]['overdue_rent'] += $remainingPayment;
                                    $amortizationData[$index]['balance_payment'] -= $remainingPayment;

                                    $breakOverDue = $paymentsData[$pIndex]['overdue_rent'];

                                    if($breakOverDue >= $amortizationData[$index]['current_interest']){
                                        $paymentsData[$pIndex]['overdue_cur_interest'] = $amortizationData[$index]['current_interest'];
                                        $paymentsData[$pIndex]['overdue_cur_principal'] = min(
                                            $breakOverDue - $amortizationData[$index]['current_interest'],
                                            $amortizationData[$index]['current_rent']
                                        );
                                    } else {
                                        $paymentsData[$pIndex]['overdue_cur_interest'] = $breakOverDue;
                                        $paymentsData[$pIndex]['current_rent'] = 0;
                                    }
                                    $remainingPayment = 0;
                                }
                            }

                            // THIRD: Any remaining payment becomes future_rent - apply to future rows
                            if ($remainingPayment > 0) {
                                $futureRows = array_filter($amortizationData, function ($row) use ($paymentDate) {
                                    return $row['due_date'] > $paymentDate && $row['completed'] == 0;
                                });

                                usort($futureRows, function ($a, $b) {
                                    return strtotime($a['due_date']) - strtotime($b['due_date']);
                                });

                                foreach ($futureRows as $futureRow) {
                                    if ($remainingPayment <= 0) break;

                                    $futureIndex = array_search($futureRow['id'], array_column($amortizationData, 'id'));
                                    if ($futureIndex === false) continue;

                                    $futureInterestAmount = $amortizationData[$futureIndex]['current_interest'];
                                    $futurePrincipalAmount = $amortizationData[$futureIndex]['current_rent'];
                                    $futureTotalAmountDue = $futureInterestAmount + $futurePrincipalAmount;

                                    if ($remainingPayment >= $futureTotalAmountDue) {
                                        $paymentsData[$pIndex]['future_interest'] += $futureInterestAmount;
                                        $paymentsData[$pIndex]['future_principal'] += $futurePrincipalAmount;
                                        $paymentsData[$pIndex]['future_rent'] += $futureTotalAmountDue;
                                        $remainingPayment -= $futureTotalAmountDue;
                                        $amortizationData[$futureIndex]['completed'] = 1;
                                        $amortizationData[$futureIndex]['balance_payment'] = 0;
                                        $amortizationData[$futureIndex]['current_interest'] = 0;
                                        $amortizationData[$futureIndex]['current_rent'] = 0;
                                    } else {
                                        if ($remainingPayment >= $futureInterestAmount) {
                                            $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                            $paymentsData[$pIndex]['future_interest'] += $futureInterestAmount;
                                            $remainingPayment -= $futureInterestAmount;
                                            $amortizationData[$futureIndex]['current_interest'] = 0;

                                            $paymentsData[$pIndex]['future_principal'] += $remainingPayment;
                                            $paymentsData[$pIndex]['future_rent'] += $futureInterestAmount + $remainingPayment;
                                            $amortizationData[$futureIndex]['current_rent'] -= $remainingPayment;
                                            $remainingPayment = 0;
                                        } else {
                                            $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                            $paymentsData[$pIndex]['future_interest'] += $remainingPayment;
                                            $paymentsData[$pIndex]['future_rent'] += $remainingPayment;
                                            $amortizationData[$futureIndex]['current_interest'] -= $remainingPayment;
                                            $remainingPayment = 0;
                                        }
                                        break;
                                    }
                                }
                            }

                            if ($remainingPayment > 0) {
                                $paymentsData[$pIndex]['excess'] = $remainingPayment;
                                $remainingPayment = 0;
                            }
                        }
                    }
                }

                // MULTIPLE ROWS - Complex handling with days diff calculation
                else {
                    // Step 1: Calculate overdue_int for each row based on days diff to next row
                    $totalBalanceSum = 0;

                    if ($rowCount <= 2){
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
                                $startTimestamp = strtotime($currentRow['due_date']);
                                if(isset($lastPaidDate)){
                                    $lastPaidTimestamp = strtotime($lastPaidDate);
                                }

                                if (isset($lastPaidDate) && $lastPaidTimestamp > $startTimestamp) {
                                    $startTimestamp = $lastPaidTimestamp;
                                }

                                $nextTimestamp = strtotime($nextRow['due_date']);
                                $daysDiff = floor(($nextTimestamp - $startTimestamp) / (60 * 60 * 24));

                                $overdue_int = ($contractDefIntRate * $daysDiff * $currentRow['balance_payment']) / 100;
                                $amortizationData[$index]['overdue_int'] = $overdue_int;
                            } else {
                                $currentTimestamp = strtotime($currentRow['due_date']);
                                $paymentTimestamp = strtotime($paymentDate);
                                $daysDiff = floor(($paymentTimestamp - $currentTimestamp) / (60 * 60 * 24));

                                if ($daysDiff > 0) {
                                    $overdue_int = ($contractDefIntRate * $daysDiff * $totalBalanceSum) / 100;
                                    $amortizationData[$index]['overdue_int'] = $overdue_int;
                                }
                            }
                        }

                        $totalOverdueInterest = 0;
                        foreach ($pendingRowsList as $row) {
                            $index = array_search($row['id'], array_column($amortizationData, 'id'));
                            if ($index !== false) {
                                $totalOverdueInterest += $amortizationData[$index]['overdue_int'];
                            }
                        }
                    } else if ($rowCount > 2){
                        for ($i = 0; $i < $rowCount; $i++) {
                            $currentRow = $pendingRowsList[$i];
                            $nextRow = $pendingRowsList[$i + 1] ?? null;
                            $nexttoNextRow = $pendingRowsList[$i + 2] ?? null;
                            $currentTimestamp = strtotime($currentRow['due_date']);

                            if ($nextRow) {
                                $nextTimestamp = strtotime($nextRow['due_date']);

                                if ($nexttoNextRow) {
                                    $nextToNextTimestamp = strtotime($nexttoNextRow['due_date']);

                                    $paymentAfterNext = array_filter($paymentsData, function($payment) use ($nextTimestamp, $nextToNextTimestamp) {
                                        $paymentTimestamp = strtotime($payment['payment_date']);
                                        return $paymentTimestamp > $nextTimestamp && $paymentTimestamp < $nextToNextTimestamp;
                                    });

                                    if (!empty($paymentAfterNext)) {
                                        $index = array_search($currentRow['id'], array_column($amortizationData, 'id'));
                                        if ($index !== false) {
                                            $amortizationData[$index]['overdue_int'] = 0;
                                        }
                                        //continue;
                                    } else if(empty($paymentAfterNext)){
                                        $paymentsTillNext = array_filter($paymentsData, function($payment) use ($currentTimestamp, $nextTimestamp) {
                                            $paymentTimestamp = strtotime($payment['payment_date']);
                                            return $paymentTimestamp > $currentTimestamp && $paymentTimestamp < $nextTimestamp;
                                        });

                                        $totalBalanceSum = 0;
                                        for ($j = 0; $j <= $i; $j++) {
                                            $prevRow = $pendingRowsList[$j];
                                            $prevIndex = array_search($prevRow['id'], array_column($amortizationData, 'id'));
                                            if ($prevIndex !== false) {
                                                $totalBalanceSum += $amortizationData[$prevIndex]['balance_payment'];
                                            }
                                        }

                                        if (!empty($paymentsTillNext)) {
                                            $lastPayment = max($paymentsTillNext);
                                            $lastPaymentTimestamp = strtotime($lastPayment['payment_date']);
                                            $daysDiff = floor(($nextTimestamp - $lastPaymentTimestamp) / 86400);
                                        } else {
                                            $daysDiff = floor(($nextTimestamp - $currentTimestamp) / 86400);
                                        }

                                        $overdue_int = ($contractDefIntRate * $daysDiff * $totalBalanceSum) / 100;
                                        $index = array_search($currentRow['id'], array_column($amortizationData, 'id'));
                                        if ($index !== false) {
                                            $amortizationData[$index]['overdue_int'] = $overdue_int;
                                        }
                                    }
                                } else {
                                    $paymentsBetween = array_filter($paymentsData, function($payment) use ($currentTimestamp, $nextTimestamp) {
                                        $paymentTimestamp = strtotime($payment['payment_date']);
                                        return $paymentTimestamp > $currentTimestamp && $paymentTimestamp < $nextTimestamp;
                                    });

                                    $totalBalanceSum = 0;
                                    for ($j = 0; $j <= $i; $j++) {
                                        $prevRow = $pendingRowsList[$j];
                                        $prevIndex = array_search($prevRow['id'], array_column($amortizationData, 'id'));
                                        if ($prevIndex !== false) {
                                            $totalBalanceSum += $amortizationData[$prevIndex]['balance_payment'];
                                        }
                                    }

                                    if (!empty($paymentsBetween)) {
                                        $lastPayment = max($paymentsBetween);
                                        $lastPaymentTimestamp = strtotime($lastPayment['payment_date']);
                                        $daysDiff = floor(($nextTimestamp - $lastPaymentTimestamp) / 86400);
                                    } else {
                                        $daysDiff = floor(($nextTimestamp - $currentTimestamp) / 86400);
                                    }

                                    $overdue_int = ($contractDefIntRate * $daysDiff * $totalBalanceSum) / 100;
                                    $index = array_search($currentRow['id'], array_column($amortizationData, 'id'));
                                    if ($index !== false) {
                                        $amortizationData[$index]['overdue_int'] = $overdue_int;
                                    }
                                }
                            } else {
                                // FINAL ROW
                                $currentTimestamp = strtotime($currentRow['due_date']);
                                $paymentTimestamp = strtotime($paymentDate);

                                // Get the index first
                                $index = array_search($currentRow['id'], array_column($amortizationData, 'id'));
                                if ($index === false) {
                                    continue; // Skip if row not found
                                }

                                $totalBalanceSum = 0;
                                for ($j = 0; $j <= $i; $j++) {
                                    $prevRow = $pendingRowsList[$j];
                                    $prevIndex = array_search($prevRow['id'], array_column($amortizationData, 'id'));
                                    if ($prevIndex !== false) {
                                        $totalBalanceSum += $amortizationData[$prevIndex]['balance_payment'];
                                    }
                                }

                                // Normalize dates to remove time component for accurate comparison
                                $currentDate = date('Y-m-d', $currentTimestamp);
                                $paymentDateOnly = date('Y-m-d', $paymentTimestamp);

                                // If payment is on or before due date, no interest
                                if ($paymentDateOnly <= $currentDate) {
                                    $daysDiff = 0;
                                    $amortizationData[$index]['overdue_int'] = 0;
                                } else {
                                    $daysDiff = floor(($paymentTimestamp - $currentTimestamp) / (60 * 60 * 24));
                                    $overdue_int = ($contractDefIntRate * $daysDiff * $totalBalanceSum) / 100;
                                    $amortizationData[$index]['overdue_int'] = $overdue_int;
                                }
                            }
                        }
                        $totalOverdueInterest = 0;
                        foreach ($pendingRowsList as $row) {
                            $index = array_search($row['id'], array_column($amortizationData, 'id'));
                            if ($index !== false) {
                                $totalOverdueInterest += $amortizationData[$index]['overdue_int'];
                            }
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
                                $paymentsData[$pIndex]['overdue_cur_principal'] = $amortizationData[$index]['current_rent'];
                                $paymentsData[$pIndex]['overdue_cur_interest'] = $amortizationData[$index]['current_interest'];
                            } else {
                                $paymentsData[$pIndex]['overdue_rent'] += $remainingPayment;
                                $amortizationData[$index]['balance_payment'] -= $remainingPayment;

                                $breakOverDue = $paymentsData[$pIndex]['overdue_rent'];

                                if($breakOverDue >= $amortizationData[$index]['current_interest']){
                                    $paymentsData[$pIndex]['overdue_cur_interest'] = $amortizationData[$index]['current_interest'];
                                    $paymentsData[$pIndex]['overdue_cur_principal'] = min(
                                        $breakOverDue - $amortizationData[$index]['current_interest'],
                                        $amortizationData[$index]['current_rent']
                                    );
                                } else {
                                    $paymentsData[$pIndex]['overdue_cur_interest'] = $breakOverDue;
                                    $paymentsData[$pIndex]['current_rent'] = 0;
                                }
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

                            $futureInterestAmount = $amortizationData[$futureIndex]['current_interest'];
                            $futurePrincipalAmount = $amortizationData[$futureIndex]['current_rent'];
                            $futureTotalAmountDue = $futureInterestAmount + $futurePrincipalAmount;

                            if ($remainingPayment >= $futureTotalAmountDue) {
                                $paymentsData[$pIndex]['future_interest'] += $futureInterestAmount;
                                $paymentsData[$pIndex]['future_principal'] += $futurePrincipalAmount;
                                $paymentsData[$pIndex]['future_rent'] += $futureTotalAmountDue;
                                $remainingPayment -= $futureTotalAmountDue;
                                $amortizationData[$futureIndex]['completed'] = 1;
                                $amortizationData[$futureIndex]['balance_payment'] = 0;
                                $amortizationData[$futureIndex]['current_interest'] = 0;
                                $amortizationData[$futureIndex]['current_rent'] = 0;
                            } else {
                                if ($remainingPayment >= $futureInterestAmount) {
                                    $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                    $paymentsData[$pIndex]['future_interest'] += $futureInterestAmount;
                                    $remainingPayment -= $futureInterestAmount;
                                    $amortizationData[$futureIndex]['current_interest'] = 0;

                                    $paymentsData[$pIndex]['future_principal'] += $remainingPayment;
                                    $paymentsData[$pIndex]['future_rent'] += $futureInterestAmount + $remainingPayment;
                                    $amortizationData[$futureIndex]['current_rent'] -= $remainingPayment;
                                    $remainingPayment = 0;
                                } else {
                                    $amortizationData[$futureIndex]['balance_payment'] -= $remainingPayment;
                                    $paymentsData[$pIndex]['future_interest'] += $remainingPayment;
                                    $paymentsData[$pIndex]['future_rent'] += $remainingPayment;
                                    $amortizationData[$futureIndex]['current_interest'] -= $remainingPayment;
                                    $remainingPayment = 0;
                                }
                                break;
                            }
                        }
                    }

                    if ($remainingPayment > 0) {
                        $paymentsData[$pIndex]['excess'] = $remainingPayment;
                        $remainingPayment = 0;
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
            $caseOverDueCurInterest = [];
            $caseOverDueCurPrincipal = [];
            $caseFutureRent = [];
            $caseFutureInterest = [];
            $caseFuturePrincipal = [];
            $caseExcess = [];
            $ids = [];

            foreach ($paymentsData as $data) {
                $currentInterest = $data['current_interest'] ?? 0;
                $currentRent = $data['current_rent'] ?? 0;
                $overDueInterest = $data['overdue_interest'] ?? 0;
                $overDueRent = $data['overdue_rent'] ?? 0;
                $overdue_cur_interest = $data['overdue_cur_interest'] ?? 0;
                $overdue_cur_principal = $data['overdue_cur_principal'] ?? 0;
                $futureRent = $data['future_rent'] ?? 0;
                $futureInterest = $data['future_interest'] ?? 0;
                $futurePrincipal = $data['future_principal'] ?? 0;
                $excess = $data['excess'] ?? 0;
                $id = $data['pymnt_id'];

                $caseCurrentInterest[] = "WHEN pymnt_id = '{$id}' THEN {$currentInterest}";
                $caseCurrentRent[] = "WHEN pymnt_id = '{$id}' THEN {$currentRent}";
                $caseOverDueInterest[] = "WHEN pymnt_id = '{$id}' THEN {$overDueInterest}";
                $caseOverDueRent[] = "WHEN pymnt_id = '{$id}' THEN {$overDueRent}";
                $caseOverDueCurInterest[] = "WHEN pymnt_id = '{$id}' THEN {$overdue_cur_interest}";
                $caseOverDueCurPrincipal[] = "WHEN pymnt_id = '{$id}' THEN {$overdue_cur_principal}";
                $caseFutureRent[] = "WHEN pymnt_id = '{$id}' THEN {$futureRent}";
                $caseFutureInterest[] = "WHEN pymnt_id = '{$id}' THEN {$futureInterest}";
                $caseFuturePrincipal[] = "WHEN pymnt_id = '{$id}' THEN {$futurePrincipal}";
                $caseExcess[] = "WHEN pymnt_id = '{$id}' THEN {$excess}";
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
                        overdue_cur_interest = CASE " . implode(' ', $caseOverDueCurInterest) . " END,
                        overdue_cur_principal = CASE " . implode(' ', $caseOverDueCurPrincipal) . " END,
                        future_rent = CASE " . implode(' ', $caseFutureRent) . " END,
                        future_interest = CASE " . implode(' ', $caseFutureInterest) . " END,
                        future_principal = CASE " . implode(' ', $caseFuturePrincipal) . " END,
                        excess = CASE " . implode(' ', $caseExcess) . " END
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
