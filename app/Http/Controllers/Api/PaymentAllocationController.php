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
                'completed' => 200,
                'contract' => $contractDetails, // Entire contract object
                'contract_no' => $contractDetails->contract_no, // Entire contract object
                'def_int_rate' => $contractDetails->def_int_rate,
                'execution_date' => $contractDetails->loan_execution_date,
            ], 200);

        } else {
            // Return a 404 response if contract is not found
            return response()->json([
                'completed' => 404,
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
                'completed' => 200,
                'message' => 'Successfully deleted amortization schedule',
            ], 200);
        }
        else{
            return response()->json([
                'completed' => 404,
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
                'completed' => 200,
                'breakdown' => $paymntBreakDowns,
            ], 200);
        }
        else{
            return response()->json([
                'completed' => 404,
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
                'completed' => 404,
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
                'overdue_int' => $item->overdue_int,
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
                'payment_amount' => $payment->payment_amount,
                'overdue_interest' => $payment->overdue_interest ?? 0,
                'overdue_rent' => $payment->overdue_rent ?? 0,
                'current_interest' => $payment->current_interest ?? 0,
                'current_rent' => $payment->current_rent ?? 0,
                'future_rent' => $payment->future_rent ?? 0,
                'allocated' => $payment->allocated ?? 0, // Assuming 'allocated' is a field in the PaymentBreakdown model
            ];
        })->toArray();



        foreach ($amortizationData as $index => &$scheduleItem) {
            echo 'Processing payment for index: '.($index). ' - ' . $amortizationData[$index]['due_date']  .'<br>';
            // Get the current due date
            $dueDate = Carbon::parse($scheduleItem['due_date']);

            $filteredPayments = collect($paymentsData)
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

            $today = Carbon::now();
            $dueDiff = $today->diffInDays($dueDate, false);

            if($dueDiff < 0) {
                $previousRow = $amortizationData[$index - 1] ?? null;

                if($previousRow){
                    echo 'has prev' .'<br>';
                        $lastPaymentDue = '';
                        $lastPaidOn = '';
                        //var_dump($filteredPayments);
                        if($filteredPayments){
                            foreach ($filteredPayments as $key => &$payment) {
                                $paymentDate = Carbon::parse($payment['payment_date']);
                                $paymentAmount = $payment['payment_amount'];
                                $deductableamount = $paymentAmount;
                                $deductableamountBreakDown = $paymentAmount;

                                echo 'Payment Date: ' . $paymentDate->toDateString() . ' - Amount: ' . $paymentAmount . '<br>';
                                $lastPaymentDue = $lastPaidOn ?: $amortizationData[$index - 1]['due_date'];
                                $arrearsDays = abs($paymentDate->diffInDays($lastPaymentDue, false));
                                //var_dump($payment);
                                if($amortizationData[$index - 1]['balance_payment'] > 0){
                                    if($arrearsDays >= 1){
                                        $lastbalanceArrearsInt = round($amortizationData[$index - 1]['balance_payment']*abs($arrearsDays)*$contractDefIntRate/100, 2);

                                        if($lastbalanceArrearsInt > $paymentAmount){
                                            $payment['overdue_interest'] = $lastbalanceArrearsInt;
                                        }
                                        $amortizationData[$index - 1]['overdue_int'] += $lastbalanceArrearsInt;
                                        $payment['overdue_interest'] =  $lastbalanceArrearsInt;
                                        $deductableamountBreakDown -= $lastbalanceArrearsInt;
                                        var_dump($deductableamountBreakDown);
                                        if($deductableamountBreakDown < $amortizationData[$index - 1]['balance_payment']){
                                            $payment['overdue_rent'] = abs($deductableamountBreakDown);
                                        } else{
                                            $payment['overdue_rent'] = $amortizationData[$index - 1]['balance_payment'];
                                        }

                                        $deductableamount -= $amortizationData[$index - 1]['overdue_int'];
                                        if($deductableamount < 0){
                                            $amortizationData[$index - 1]['overdue_int'] = abs($deductableamount);
                                        } else{
                                            $amortizationData[$index - 1]['overdue_int'] = 0;
                                            $amortizationData[$index - 1]['balance_payment'] -= $deductableamount;
                                            if($amortizationData[$index - 1]['balance_payment'] < 0) {
                                                $amortizationData[$index]['balance_payment'] -= abs($amortizationData[$index - 1]['balance_payment']);
                                                $payment['future_rent'] = abs($amortizationData[$index - 1]['balance_payment']);
                                                $amortizationData[$index - 1]['balance_payment'] = 0;
                                            }
                                        }
                                    }
                                    else{
                                        echo 'paid on time ' . $paymentAmount . '<br>';
                                        echo $amortizationData[$index - 1]['balance_payment'] . '<br>';
                                        echo $amortizationData[$index]['balance_payment'] . '<br>';
                                    }
                                    $payment['allocated'] = 1; // Mark as allocated
                                } else {
                                    echo 'current ' . $amortizationData[$index]['balance_payment'] . '<br>';

                                    if($payment){
                                        echo $payment['allocated'] . '<br>';
                                        if($payment['allocated'] == 1){
                                            echo 'allocated ' . $payment['pymnt_id'] . '<br>';
                                            continue; // Skip already allocated payments
                                        } else {
                                            echo 'not allocated ' . $payment['payment_amount'] . '<br>';
                                            if($amortizationData[$index]['balance_payment'] > $paymentAmount){
                                                $payment['current_rent'] = $paymentAmount;
                                            } else {
                                                $payment['current_rent'] = $amortizationData[$index]['balance_payment'];
                                            }

                                            echo 'current balance : ' . $amortizationData[$index]['balance_payment'] . '<br>';
                                            echo ' - last  balance : ' . $amortizationData[$index - 1]['balance_payment'] . '<br>';

                                            $amortizationData[$index]['balance_payment'] -= abs($paymentAmount);
                                            if($amortizationData[$index]['balance_payment'] < 0) {
                                                echo 'balance payment is less than 0 (' . $amortizationData[$index]['balance_payment'] . ') <br>';
                                                $payment['future_rent'] = abs($amortizationData[$index]['balance_payment']);
                                                $remainingAmount = abs($amortizationData[$index]['balance_payment']);
                                                $i = $index + 1;
                                                // Loop through subsequent payments until we've distributed the full amount
                                                while ($remainingAmount > 0 && isset($amortizationData[$i])) {
                                                    // Calculate how much we can deduct from this payment
                                                    $deductible = min($remainingAmount, $amortizationData[$i]['balance_payment']);
                                                    echo 'reducing from ' .  $amortizationData[$i]['balance_payment'] . ' by ' . $deductible . '<br>';
                                                    // Reduce both the current payment and the remaining amount
                                                    $amortizationData[$i]['balance_payment'] -= $deductible;
                                                    $remainingAmount -= $deductible;
                                                    echo $amortizationData[$i]['balance_payment'] . '<br>';
                                                    $i++;
                                                }
                                                // $amortizationData[$index + 1]['balance_payment'] -= abs($amortizationData[$index]['balance_payment']);
                                                // $payment['future_rent'] = abs($amortizationData[$index]['balance_payment']);
                                                $amortizationData[$index]['balance_payment'] = 0;
                                            }
                                            $payment['allocated'] = 1; // Mark as allocated
                                        }

                                    } else {
                                        echo 'No payment found for this due date!!!: ' . $scheduleItem['due_date'] . '<br>';
                                        echo ' current  '. $amortizationData[$index]['balance_payment'] . '<br>';
                                        continue;
                                    }

                                }



                                $lastPaidOn = $paymentDate->toDateString();

                                $key = array_search($payment['pymnt_id'], array_column($paymentsData, 'pymnt_id'));

                                if ($key !== false) {
                                    $paymentsData[$key] = $payment; // Update existing
                                }

                            }
                            if($amortizationData[$index - 1]['balance_payment'] > 0){
                                $frontArrearsDays = abs(Carbon::parse($amortizationData[$index]['due_date'])->diffInDays($lastPaidOn, false));
                                $amortizationData[$index]['balance_payment'] += $amortizationData[$index - 1]['balance_payment'] + round($amortizationData[$index - 1]['balance_payment']*abs($frontArrearsDays)*$contractDefIntRate/100, 2);
                                $amortizationData[$index - 1]['balance_payment'] = 0;
                            }

                        }
                        else{
                            echo 'No payments found for this due date: ' . $scheduleItem['due_date'] . '<br>';
                            echo 'Last balance : ' . $amortizationData[$index - 1]['balance_payment'] . '<br>';
                            $arrearsDays = abs(Carbon::parse($amortizationData[$index]['due_date'])->diffInDays($amortizationData[$index - 1]['due_date'], false));
                            echo 'Arrears Days: ' . $arrearsDays . '<br>';
                            $lastbalanceArrearsInt = round($amortizationData[$index - 1]['balance_payment']*abs($arrearsDays)*$contractDefIntRate/100, 2);
                            $amortizationData[$index]['overdue_int'] += $lastbalanceArrearsInt + $amortizationData[$index - 1]['overdue_int'];
                            $amortizationData[$index]['balance_payment'] += $amortizationData[$index - 1]['balance_payment'];
                            $amortizationData[$index - 1]['balance_payment'] = 0;
                        }


                } else {
                    echo 'has no prev' .'<br>';
                    if($filteredPayments){
                        foreach ($filteredPayments as $key => &$payment) {
                            $amortizationData[$index]['balance_payment'] -= $payment['payment_amount'];
                        }
                    }
                    continue;
                }

            }


        }

        //var_dump($paymentsData);

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

        //var_dump($paymentsToUpdate);

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
    }


}
