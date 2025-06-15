<?php

namespace App\Http\Controllers\Api;
use App\Models\Payment;
use App\Models\PaymentBreakdown;
use App\Models\Contract;
use App\Models\MasterAmortization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Services\AmortizationService;
use App\Services\PaymentBreakdownService;
use DateTime;
use Carbon\Carbon;
use PhpParser\Node\Stmt\Echo_;
use PHPUnit\Event\Runtime\PHP;

class PaymentAllocationController extends Controller
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

    public function getContractDetails($contract_no)
    {
        // Fetch contract details
        $contractDetails = Contract::where('contract_no', $contract_no)->first();

        // Check if contract exists
        if ($contractDetails) {
            return response()->json([
                'status' => 200,
                'contract' => $contractDetails, // Entire contract object
                'contract_no' => $contractDetails->contract_no, // Entire contract object
                'def_int_rate' => $contractDetails->def_int_rate,
                'execution_date' => $contractDetails->loan_execution_date,
            ], 200);

        } else {
            // Return a 404 response if contract is not found
            return response()->json([
                'status' => 404,
                'message' => 'Contract not found'
            ], 404);
        }
    }

    public function getAmortizationSchedule($contract)
    {
        // Fetch or generate the amortization schedule
        $amortizationSchedule = $this->amortizationService->getOrGenerateAmortizationSchedule($contract);

        if($amortizationSchedule){
            return response()->json([
                'status' => 200,
                'message' => 'Successfully deleted amortization schedule',
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'Unable to delete amortization schedule',
            ], 404);
        }
    }

    public function refreshPaymentBreakdown($contract_no)
    {
        // Fetch or generate the amortization schedule
        $paymntBreakDowns = $this->paymentBreakdownService->refreshPaymentBreakdown($contract_no);

        if($paymntBreakDowns){
            return response()->json([
                'status' => 200,
                'breakdown' => $paymntBreakDowns,
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'Unable to delete amortization schedule',
            ], 404);
        }
    }

    public function getPaymentBreakdown($contract_no){
        $paymentBreakdown = PaymentBreakdown::where('contract_no', $contract_no)->get();

        if ($paymentBreakdown->isNotEmpty()) {
            return $paymentBreakdown;
        } else {
            return response()->json([
                'status' => 404,
                'message' => 'No payment breakdowns found for this contract.'
            ], 404);
        }
    }


    public function allocatePayments($contract_no){

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
                'original_balance_payment' => $item->payment, // Will be modified
                'balance_payment' => $item->balance_payment, // Will be modified
                'current_interest' => $item->balance_interest,
                'current_rent' => $item->balance_principal,
                'status' => 0, // 0 = incomplete, 1 = complete
            ];
        })->toArray();

        $payments = PaymentBreakdown::where('contract_no', $contract_no)
        ->orderBy('payment_date')
        ->get();

        $paymentsData = $payments->map(function ($payment) {
            return [
                'pymnt_id' => $payment->pymnt_id,
                'payment_date' => $payment->payment_date,
                'payment_amount' => $payment->payment_amount,
                'overdue_interest' => $payment->overdue_interest ?? 0,
            ];
        })->toArray();

        if ($payments->isEmpty()) {
            return response()->json([
                'status' => 404,
                'message' => 'No payments found for this contract.'
            ], 404);
        } else {

        $dailyInterestRate = $contractDefIntRate;


        $paymentsToUpdate = [];
        $InterestAllocation = 0;
        $totalAmortizations = count($amortizationData);

        foreach ($amortizationData as $index => &$scheduleItem) {

            echo 'Processing payment for index: '.($index). ' - ' . $amortizationData[$index]['due_date']  .'<br>';
            // Get the current due date
            $dueDate = Carbon::parse($scheduleItem['due_date']);

            $filteredPayments = collect($payments)
            ->filter(function ($payment) use ($index, $amortizationData, $dueDate) {
                $paymentDate = Carbon::parse($payment['payment_date']);

                // For first index (0): get all payments <= due_date
                if ($index === 0) {
                    return $paymentDate->lte($dueDate);
                }

                // For other indices: get payments between previous due_date and current due_date
                $startDate = Carbon::parse($amortizationData[$index - 1]['due_date']);
                return $paymentDate->between($startDate, $dueDate);
            })
            ->values()
            ->all();

            $previousRow = $amortizationData[$index - 1] ?? null;

            if($previousRow){
                $today = new DateTime();
                if (empty($filteredPayments)) {
                    echo ":( No applicable payments for index $index with due date {$scheduleItem['due_date']}  -  " . $amortizationData[$index]['balance_payment'] . "<br>";
                    //$amortizationData[$index - 1]['balance_payment'] = $previousRow['balance_payment'];
                    if($amortizationData[$index - 1]['status'] != 1){
                        $amortizationData[$index]['balance_payment'] += $amortizationData[$index -1 ]['balance_payment'];
                        $amortizationData[$index - 1]['status'] = 1; // complete
                    }

                    if($amortizationData[$index + 1]['due_date'] < $today){
                        $amortizationData[$index + 1]['balance_payment'] +=  $amortizationData[$index]['balance_payment'];
                        echo 'added ' . $amortizationData[$index]['balance_payment'] . ' to ' . $amortizationData[$index + 1]['due_date']  . ' : ' . $amortizationData[$index+1]['balance_payment'] . "<br>";
                        $amortizationData[$index]['status'] = 1; // complete
                    }
                }
                else {
                    $lastPaymentDue = '';
                    $arrearsIntUpdate = 0;
                    $arrearsRentValue = 0;
                    $futureRentValue = 0;
                    $currentInterest = 0;
                    $currentRent = 0;
                    foreach ($filteredPayments as $key => &$payment) {
                        $currentPayment = $payment;
                        $nextPayment = next($filteredPayments); // Advances pointer and returns next value
                        $paymentDate = Carbon::parse($payment->payment_date);
                        $paymentAmount = $payment->payment_amount;
                        $deductableamount = $paymentAmount;

                        echo $paymentDate->format('Y-m-d') . ' - ' . $paymentAmount . '<br>';

                        if($previousRow && $previousRow['status'] == 0){
                            echo 'no!!!!! incomplet prev <br/>';
                            $lastPaymentDue = $lastPaymentDue ?: $previousRow['due_date'];
                            $arrearsDays = abs($paymentDate->diffInDays($lastPaymentDue, false));
                            $lastbalanceArrearsInt = $previousRow['balance_payment']*abs($arrearsDays)*$dailyInterestRate/100;

                            echo 'no!!!!! incomplet prev ' .  $arrearsDays . ' days arrears with ' .  $lastbalanceArrearsInt . ' interest ' . ' <br/>';
                            $arrearsIntUpdate = $lastbalanceArrearsInt;
                            if($deductableamount >= $arrearsIntUpdate){
                                $arrearsRentValue = $deductableamount - $arrearsIntUpdate;
                            } else if($deductableamount < $arrearsIntUpdate){
                                $arrearsIntUpdate = $deductableamount;
                                $arrearsRentValue = 0;
                            }
                            $previousRow['balance_payment'] += $lastbalanceArrearsInt;
                            $previousRow['balance_payment'] -= $paymentAmount;
                            $amortizationData[$index-1]['balance_payment'] = $previousRow['balance_payment'];
                            $lastPaymentDue = $paymentDate;
                            $amortizationData[$index]['balance_payment'] += $previousRow['balance_payment'];
                        } else if($previousRow && $previousRow['status'] == 1){
                            echo 'completed prev <br/>';
                            if($previousRow['balance_payment'] > 0){
                                $lastPaymentDue = $lastPaymentDue ?: $previousRow['due_date'];
                                echo 'Prev ' . $index-1 . ' ' . $amortizationData[$index-1]['due_date'] .' Has Balance!!! <br/>';
                                echo 'last due : ' . $lastPaymentDue . '<br/>';
                                $arrearsDays = abs($paymentDate->diffInDays($lastPaymentDue, false));
                                echo 'diff : ' . $arrearsDays . '<br/>';

                                echo 'pre-prevBalance  : ' . $previousRow['balance_payment'] . '<br/>';
                                $lastbalanceArrearsInt = $previousRow['balance_payment']*abs($arrearsDays)*$dailyInterestRate/100;
                                $arrearsIntUpdate = $lastbalanceArrearsInt;
                                echo '$lastbalanceArrearsIn :  '. $lastbalanceArrearsInt;
                                if($paymentAmount <= $lastbalanceArrearsInt){
                                    $arrearsIntUpdate = $deductableamount;
                                    $arrearsRentValue = 0;
                                } else {
                                    $arrearsIntUpdate = $lastbalanceArrearsInt;
                                    $arrearsRentValue = $deductableamount - $lastbalanceArrearsInt;
                                }
                                $previousRow['balance_payment'] += $lastbalanceArrearsInt;
                                $previousRow['balance_payment'] -= $paymentAmount;
                                echo 'prevBalance  : ' . $previousRow['balance_payment'] . '<br/>';
                                $amortizationData[$index-1]['balance_payment'] = $previousRow['balance_payment'];

                                echo 'prevBalance  : ' . $amortizationData[$index-1]['balance_payment'] . '<br/>';



                                $calCounter = $index;
                                $itemsLeft = $totalAmortizations - ($calCounter);
                                echo "Items left: " . $itemsLeft . '<br/>';

                                while ($calCounter < $totalAmortizations) {  // Use < instead of <= to avoid off-by-one
                                echo "Processing index: " . $calCounter . '<br/>';
                                    $amortizationData[$calCounter]['balance_payment'] = $amortizationData[$calCounter]['original_balance_payment'];
                                    $amortizationData[$calCounter]['status'] = 0;
                                    $calCounter++;  // Critical: Increment counter
                                }


                                if($amortizationData[$index-1]['balance_payment'] <= 0){
                                    $amortizationData[$index-1]['status'] = 1;
                                } else {
                                    //$next = $payment[2];
                                    $arrearsDays = abs(Carbon::parse($today)->diffInDays($amortizationData[$index-1]['due_date'], false));
                                    if($nextPayment){
                                        echo '$today is : ' . Carbon::parse($today) .'<br/>';

                                        echo '$due Diff has next payment : ' . $arrearsDays .'<br/>';
                                        echo '@@@@@@@@2 has next payment!' . '<br/>';
                                        if($arrearsDays >= 1){
                                            $amortizationData[$index]['balance_payment'] += $amortizationData[$index-1]['balance_payment'];
                                            $amortizationData[$index-1]['status'] = 1;
                                        } else {
                                            $amortizationData[$index-1]['status'] = 0;
                                        }

                                        echo 'changed status to : ' . $amortizationData[$index-1]['status'] . '<br/>';
                                    } else {
                                        echo 'no payment after : ' . $payment->payment_date .'<br/>';
                                        if($arrearsDays >= 1){
                                            $calCounter = $index;
                                            $itemsLeft = $totalAmortizations - ($calCounter);
                                            echo "Items left: " . $itemsLeft . '<br/>';

                                            while ($calCounter < $totalAmortizations) {  // Use < instead of <= to avoid off-by-one
                                            echo "Processing index: " . $calCounter . '<br/>';
                                                $amortizationData[$calCounter]['balance_payment'] = $amortizationData[$calCounter]['original_balance_payment'];
                                                $amortizationData[$calCounter]['status'] = 0;
                                                $calCounter++;  // Critical: Increment counter
                                            }

                                            $amortizationData[$index]['balance_payment'] +=  $amortizationData[$index-1]['balance_payment'];
                                            $amortizationData[$index-1]['status'] = 1;
                                            echo 'payment Updated to : ' . $amortizationData[$index]['balance_payment'] .'<br/>';
                                        }
                                    };

                                }

                                $lastPaymentDue = $paymentDate;

                                echo '$$lastbalanceArrearsInt : ' . $lastbalanceArrearsInt. '<br/>';
                                echo '$$arrearsRentValue : ' . $arrearsRentValue. '<br/>';

                            } else {

                                if($amortizationData[$index]['status'] == 1){
                                    echo 'completed current <br/>';
                                            $deductableAmount = $futureRentValue = $paymentAmount;
                                            $i = 1;
                                            while ($deductableAmount > 0) {
                                                echo ' has ' . $amortizationData[$index+$i]['balance_payment']. ' on  '.  $amortizationData[$index+$i]['due_date'] . ' <br/>';
                                                if($amortizationData[$index+$i]['balance_payment'] >= $deductableAmount){
                                                    $amortizationData[$index+$i]['balance_payment'] -= $deductableAmount;
                                                    $deductableAmount = 0;
                                                } else {
                                                    $amortizationData[$index+$i]['balance_payment'] -= $deductableAmount;
                                                    $deductableAmount -= $amortizationData[$index+$i]['balance_payment'];
                                                }

                                                if($amortizationData[$index+$i]['balance_payment'] < 0){
                                                    $amortizationData[$index+$i]['status'] = 1;
                                                    echo 'completed '.  $amortizationData[$index+$i]['due_date'] . ' <br/>';
                                                } else {
                                                    echo $amortizationData[$index+$i]['balance_payment'] . ' left on  '.  $amortizationData[$index+$i]['due_date'] . ' <br/>';
                                                }
                                                $i++;
                                            }
                                } else {
                                    echo 'incomplete current ' . $amortizationData[$index]['balance_payment'] . ' <br/>';
                                    if($amortizationData[$index]['balance_payment'] <= $paymentAmount){
                                        $amortizationData[$index]['status'] = 1;
                                        $amortizationData[$index]['balance_payment'] -= $paymentAmount;
                                        if ($amortizationData[$index]['balance_payment'] < 0){
                                            $deductableAmount = $futureRentValue = abs($amortizationData[$index]['balance_payment']);
                                            echo 'paid ' . $deductableAmount . ' future rent  <br/>';
                                            $i = 1;
                                            while ($deductableAmount > 0) {
                                                if($amortizationData[$index+$i]['balance_payment'] >= $deductableAmount){
                                                    echo 'deducted ' . $deductableAmount . ' from ' . $amortizationData[$index+$i]['balance_payment'] .   '<br/>';
                                                    $amortizationData[$index+$i]['balance_payment'] -= $deductableAmount;
                                                    echo $amortizationData[$index+$i]['balance_payment'] . ' left  on ' .  $amortizationData[$index+$i]['due_date'] .   '<br/>';
                                                    $deductableAmount = 0;
                                                } else {
                                                    $amortizationData[$index+$i]['balance_payment'] -= $deductableAmount;
                                                    $deductableAmount -= $amortizationData[$index+$i]['balance_payment'];

                                                    if($amortizationData[$index+$i]['balance_payment'] < 0){
                                                        $amortizationData[$index+$i]['status'] = 1;
                                                    }
                                                }
                                                $i++;
                                            }
                                            unset($i);
                                        }
                                    } else {
                                        echo 'less than balance <br/>';
                                        $deductableAmount = $paymentAmount;
                                        $balancePayment = $amortizationData[$index]['balance_payment'];
                                        $currentInterest = $amortizationData[$index]['current_interest'];
                                        $currentRent = $amortizationData[$index]['current_rent'];

                                        //$arrearsRentValue = $balancePayment - $currentRent -$currentInterest;
                                        echo $index . ' $$balancePayment : ' . $balancePayment . '<br/>';

                                        if($deductableAmount >= $balancePayment){
                                            $balancePayment -= $deductableAmount;
                                            $futureRentValue = abs($balancePayment);
                                            $amortizationData[$index]['status'] = 1;

                                            if($amortizationData[$index+1]){
                                                $amortizationData[$index+1]['balance_payment'] += $balancePayment;
                                                if($amortizationData[$index+1]['balance_payment'] < 0){
                                                    $amortizationData[$index+1]['balance_payment']['status'] = 1;
                                                }
                                            }
                                        } else {
                                            $arrearsRentValue = $deductableAmount;
                                            $balancePayment -= $deductableAmount;
                                            $deductableAmount = 0;
                                            echo ' added  : ' . $balancePayment .  '<br/>';
                                            $amortizationData[$index]['status'] = 0;
                                            echo ' made : ' .  $amortizationData[$index]['due_date'] . ' '. $amortizationData[$index]['status'] .' <br/>';
                                            $currentInterest = 0;
                                            $currentRent = 0;
                                        }

                                        if($deductableAmount > 0){
                                            if( $currentInterest <= $deductableAmount){
                                                $deductableAmount -= $currentInterest;
                                                if($deductableAmount > 0){
                                                    if($currentRent <= $deductableAmount){
                                                        $deductableAmount -= $currentRent;
                                                    } else {
                                                            $currentRent = $deductableAmount;
                                                    }
                                                }
                                            } else {
                                                if($deductableAmount > 0){
                                                    $currentInterest = $deductableAmount;
                                                }
                                            }
                                        }

                                        $amortizationData[$index]['balance_payment'] -= $paymentAmount;
                                        $paymentAmount = 0;
                                    }
                                }

                            }




                        }
                        //echo $arrearsIntUpdate;
                        $paymentsToUpdate[] = [
                            'pymnt_id' => $payment['pymnt_id'],
                            'payment_date' => $payment['payment_date'],
                            'overdue_interest' => $arrearsIntUpdate,
                            'overdue_rent' => $arrearsRentValue,
                            'future_rent' => $futureRentValue,
                            'current_interest' => $currentInterest,
                            'current_rent' => $currentRent,
                        ];

                    }
                    unset($payment); // Unset to avoid memory issues in large loops


                    $previousRow['status'] = 1;
                    $amortizationData[$index - 1] = $previousRow;
                    //$lastPaymentDue = '';


                }

            } else {
                if (empty($filteredPayments)) {
                    echo "No applicable payments for index $index with due date {$scheduleItem['due_date']}<br>";
                    continue;
                }
                else {
                    foreach ($filteredPayments as &$payment) {
                        $currentInterest = $currentRent = $futureRent = 0;
                        $DeductableAmnt = $payment['payment_amount'];

                        if($DeductableAmnt >= $amortizationData[$index]['current_interest']){
                            $currentInterest =  $amortizationData[$index]['current_interest'];
                            $DeductableAmnt -= $amortizationData[$index]['current_interest'];
                            if($DeductableAmnt >= $amortizationData[$index]['current_rent']){
                                $currentRent = $amortizationData[$index]['current_rent'];
                                $DeductableAmnt -= $amortizationData[$index]['current_rent'];
                            } else {
                                $currentRent = $DeductableAmnt;
                                $DeductableAmnt = 0;
                            }
                        } else {
                            $currentInterest = $DeductableAmnt;
                            $DeductableAmnt = 0;
                        }
                        if($DeductableAmnt > 0){
                            $futureRent = $DeductableAmnt;
                        } else {
                            $futureRent = 0;
                        }
                        //$currentInterest = $DeductableAmnt - $amortizationData[$index]['current_interest'];
                        //$currentRent = $amortizationData[$index]['balance_payment'] - $amortizationData[$index]['current_rent'];
                        $paymentsToUpdate[] = [
                            'pymnt_id' => $payment['pymnt_id'],
                            'payment_date' => $payment['payment_date'],
                            'current_interest' => $currentInterest,
                            'current_rent' => $currentRent,
                            'future_rent' => $futureRent,
                        ];
                    }
                    $amortizationData[$index]['balance_payment']-= collect($filteredPayments)->sum('payment_amount');
                    if($amortizationData[$index]['balance_payment'] <= 0){
                        $amortizationData[$index]['status'] = 1; // Mark as complete
                        $amortizationData[$index + 1]['balance_payment'] += $amortizationData[$index]['balance_payment']; // Set balance to zero


                    } else {
                        $amortizationData[$index]['status'] = 0; // Still incomplete
                    }
                }
            }

            $lastPaymentDue = null;
        }


        $caseBalance = [];
        $caseCompleted = [];
        $dueDates = [];

        foreach ($amortizationData as $row) {
            $dueDate = $row['due_date'];
            $caseBalance[] = "WHEN due_date = '{$dueDate}' THEN {$row['balance_payment']}";
            $caseCompleted[] = "WHEN due_date = '{$dueDate}' THEN {$row['status']}";
            $dueDates[] = "'{$dueDate}'";
        }
        unset($row); // Unset the last element to avoid duplicate key error


        DB::update("
            UPDATE  master_amortization
            SET
                balance_payment = CASE ".implode(' ', $caseBalance)." END,
                completed = CASE ".implode(' ', $caseCompleted)." END
            WHERE
                contract_no = ? AND
                due_date IN (".implode(',', $dueDates).")
        ", [$contract_no]);
        }

        // Update payment breakdowns
        $caseInterest = [];
        $caseRent = [];
        $caseOverDueInterest = [];
        $caseOverDueRent = [];
        $caseFutureRent = [];
        $ids = [];

        //var_dump($paymentsToUpdate);

        foreach ($paymentsToUpdate as $data) {
            $overDueInterest = $data['overdue_interest'] ?? 0; // Default to 0 if not set
            $overDueRent = $data['overdue_rent'] ?? 0; // Default to 0 if not set
            $interest = $data['current_interest'] ?? 0; // Default to 0 if not set
            $rent = $data['current_rent'] ?? 0; // Default to 0 if not set
            $futureRent = $data['future_rent'] ?? 0; // Default to 0 if not set

            $id = $data['pymnt_id'];
            $caseOverDueInterest[] = "WHEN pymnt_id = '{$id}' THEN {$overDueInterest}";
            $caseOverDueRent[] = "WHEN pymnt_id = '{$id}' THEN {$overDueRent}";
            $caseInterest[] = "WHEN pymnt_id = '{$id}' THEN {$interest}";
            $caseRent[] = "WHEN pymnt_id = '{$id}' THEN {$rent}";
            $caseFutureRent[] = "WHEN pymnt_id = '{$id}' THEN {$futureRent}";
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
                future_rent = CASE ".implode(' ', $caseFutureRent)." END
            WHERE pymnt_id IN (".implode(',', $ids).")
        ");



    }


}
