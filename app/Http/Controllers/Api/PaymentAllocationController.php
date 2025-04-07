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
                'def_int_rate' => $contractDetails->def_int_rate
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



    public function allocatePayments($contract_no){
        $allBreakdowns = PaymentBreakdown::where('contract_no', $contract_no)
        ->get();

        $contractDetails = $this->getContractDetails($contract_no);

        // Decode the JSON content
        $data = $contractDetails->getData();

        // Access the def_int_rate value
        $contractDefIntRate = $data->def_int_rate;

        if($allBreakdowns->isNotEmpty()){
            $lastBreakdown = PaymentBreakdown::where('contract_no', $contract_no)
            ->orderBy('payment_date', 'desc')  // Or use payment_date if more appropriate
            ->first([
                'pymnt_id',
                'payment_date',
                'future_rent',
                'allocated'
            ]);

            if ($lastBreakdown) {
                $lastPayment = [
                    'pymnt_id' => $lastBreakdown->pymnt_id,
                    'payment_date' => $lastBreakdown->payment_date,
                    'future_rent' => $lastBreakdown->future_rent,
                    'allocated' => $lastBreakdown->allocated
                ];

                $filteredMissingBreakdowns = $this->paymentBreakdownService->filterMissingBreakdowns($contract_no);

                foreach ($filteredMissingBreakdowns as &$paymntBreakDown){

                    $excess = 0;
                    $updatedBalance = 0;
                    $varAmorComplete = 0;
                    $prev_payment_date = $lastPayment['payment_date'];
                    $prev_payment_id = $lastPayment['pymnt_id'];
                    $prev_allocated = $lastPayment['allocated'];
                    $allocated = 0;

                    if($prev_allocated == 0){
                        $carryOverExcess = $lastPayment['future_rent'];
                    }
                    else{
                        $carryOverExcess = 0;
                    }

                    if($carryOverExcess > 0 && $prev_payment_id){
                        $prevBreakSchedule = PaymentBreakdown::where('pymnt_id', $prev_payment_id)
                        ->where('contract_no', $contract_no)
                        ->where('allocated', 0)
                        ->first();
                        if($prevBreakSchedule && $prev_allocated == 0){
                            $prevBreakSchedule->update([
                                'allocated' => 1,
                            ]);
                        }
                    }

                    $amortizationSchedule = MasterAmortization::where('contract_no', $contract_no)
                    ->where('completed', false)
                    ->first();


                    $deductable = $paymntBreakDown['payment_amount'];
                    $pymnt_id = $paymntBreakDown['pymnt_id'];
                    $payment_date = Carbon::parse($paymntBreakDown['payment_date']);
                    $due_date = Carbon::parse($amortizationSchedule['due_date']);



                    $excess = $carryOverExcess;
                    $carryOverExcess = 0;


                    $interestAllocation = $amortizationSchedule['balance_interest'];
                    $principalAllocation = $amortizationSchedule['balance_principal'];

                    $overDueInterest = 0;
                    $overdueRent = 0;
                    $breakOverdueRent = null;

                    $amortInterestAllocation = $amortizationSchedule['balance_interest'];
                    $amortPrincipalAllocation = $amortizationSchedule['balance_principal'];

                    $updatedBalance = $amortizationSchedule['balance_payment'];
                    //$excess = $this->$excess;
                    if($deductable < $updatedBalance){
                        $allocated = 1;
                    }

                    if ($payment_date <= $due_date) {

                        $overDueInterest = 0;
                        $overdueRent = 0;
                        // Apply current payment
                        if ($excess > 0) {
                            if($excess < $updatedBalance){
                                $updatedBalance -= $excess;
                            } else if($excess >= $updatedBalance){
                                $updatedBalance = 0;
                            }
                            if ($excess >= $interestAllocation) {
                                $excess -= $interestAllocation;
                            }
                            else if ($excess < $interestAllocation) {
                                $amortInterestAllocation -= $excess;
                                $interestAllocation -= $excess;
                                $excess = 0;
                            }

                            if ($excess >= $principalAllocation) {
                                $excess -= $principalAllocation;
                            }
                            else if ($excess < $principalAllocation) {
                                $amortPrincipalAllocation -= $excess;
                                $principalAllocation -= $excess;
                                $excess = 0;
                            }
                        }

                        if ($deductable > 0) {
                            if($updatedBalance >= $deductable){
                                $updatedBalance -= $deductable;
                            } else if($updatedBalance < $deductable){
                                $updatedBalance = 0;
                            }

                            if ($deductable >= $interestAllocation) {
                                $deductable -= $interestAllocation;
                                $amortInterestAllocation = 0;
                            }
                            else if ($deductable < $interestAllocation) {
                                $interestAllocation = $deductable;
                                $amortInterestAllocation -= $deductable;
                                $deductable = 0;
                            }

                            if ($deductable >= $principalAllocation) {
                                $deductable -= $principalAllocation;
                                $amortPrincipalAllocation = 0;
                            }
                            else if ($deductable < $principalAllocation) {
                                $amortPrincipalAllocation -= $deductable;
                                $principalAllocation = $deductable;
                                $deductable = 0;
                            }
                        }

                        if ($deductable > 0) {
                            $excess += $deductable;
                            $carryOverExcess = $excess;
                        }
                    }
                    elseif($payment_date > $due_date){

                        // $interestAllocation = 0;
                        // $principalAllocation = 0;

                        $prev_payment_date = Carbon::parse($prev_payment_date);
                        $currentTimestamp = Carbon::parse($payment_date)
                        ->hour(0)
                        ->minute(0)
                        ->second(0);
                        if($prev_payment_date){
                            if($prev_payment_date <= $due_date){
                                $DiffV = ($currentTimestamp)->diffInDays($due_date);
                            }
                            elseif($prev_payment_date > $due_date){
                                $DiffV = ($currentTimestamp)->diffInDays($prev_payment_date);
                            }
                            $overdueRent = round($updatedBalance, 2);
                            $overDueInterest = $DiffV * $contractDefIntRate * $overdueRent / 100;
                            $overDueInterest = round($overDueInterest, 2);
                            $updatedBalance += $overDueInterest;

                            if($deductable > $updatedBalance){
                                $allocated = 1;
                            }

                            // Apply current payment
                            if ($excess > 0) {
                                if($updatedBalance >= $excess){
                                    $updatedBalance = 0;
                                }
                                elseif($excess < $updatedBalance){
                                    $updatedBalance -= $excess;
                                }

                                if ($excess >= $overDueInterest) {
                                    $excess -= $overDueInterest;
                                }
                                elseif ($excess < $overDueInterest) {
                                    //$amortInterestAllocation -= $excess;
                                    $overDueInterest -= $excess;
                                    $excess = 0;
                                }
                                if ($excess >= $overdueRent) {
                                    if ($excess >= $interestAllocation) {
                                        $interestAllocation = $excess;
                                    } else {
                                        $interestAllocation = 0;
                                    }

                                    $excess -= $overdueRent;
                                }
                                elseif ($excess < $overdueRent) {

                                    if ($excess >= $principalAllocation) {
                                        $principalAllocation = $excess;
                                    } else {
                                        $principalAllocation = 0;
                                    }

                                    $overdueRent -= $excess;
                                    $excess = 0;
                                }
                            }

                            if ($deductable > 0) {

                                if($updatedBalance > $deductable){
                                    $updatedBalance -= $deductable;
                                } elseif($deductable >= $updatedBalance){
                                    $updatedBalance = 0;
                                    $allocated = 1;
                                }

                                if($deductable >= $overDueInterest) {
                                    $deductable -= $overDueInterest;

                                    //echo 'deductable after overDueInterest' . $deductable . PHP_EOL;

                                    if ($deductable >= $overdueRent) {
                                        //echo 'deductable >= overdueRent' . PHP_EOL;

                                        if ($deductable >= $interestAllocation) {

                                            $carryoverPrin = $deductable;

                                            $carryoverPrin -= $interestAllocation;

                                            if ($carryoverPrin >= $principalAllocation) {
                                                $principalAllocation = $carryoverPrin;
                                            } else {
                                                $principalAllocation = 0;
                                            }

                                        } else {
                                            $interestAllocation = 0;
                                            $principalAllocation = $deductable;
                                        }

                                        $deductable -= $overdueRent;
                                        $overdueRent = 0;
                                    }
                                    elseif ($deductable < $overdueRent) {

                                        $allocated = 1;

                                        $overdueRent = $deductable;

                                        //$amortInterestAllocation = $overdueRent;
                                        if($deductable > $interestAllocation){
                                            $amortInterestAllocation = 0;
                                        }
                                        else{
                                            $amortInterestAllocation = $interestAllocation;
                                            $amortInterestAllocation -= $deductable;
                                        }

                                        $deductable -= $interestAllocation;

                                        if ($deductable > 0) {
                                            if($deductable >= $principalAllocation) {
                                                $amortPrincipalAllocation = 0;
                                            } else {
                                                $amortPrincipalAllocation = $principalAllocation;
                                                $principalAllocation = $deductable;
                                                $amortPrincipalAllocation -= $deductable;
                                            }
                                        }

                                        $deductable = 0;
                                    }
                                }
                                elseif ($deductable < $overDueInterest) {
                                    $overDueInterest -= $deductable;
                                    $allocated = 1;
                                    $deductable = 0;
                                }
                            }

                            if ($deductable > 0) {
                                $excess += $deductable;
                                $carryOverExcess = $excess;
                                $allocated = 0;
                            }
                        }

                    }

                    if($updatedBalance <= 0){
                        $varAmorComplete = 1;
                    }
                    else{
                        $varAmorComplete = 0;
                    }

                    if($updatedBalance == 0 && $deductable == 0){
                        if(!($carryOverExcess > 0)){
                            $allocated = 1;
                        }
                    };

                    if($excess > 0){
                        $allocated = 1;
                    }

                    $updateBreak = $this->updateBreakdown(
                        $contract_no,
                        $pymnt_id,
                        $overDueInterest,
                        $overdueRent,
                        $interestAllocation ,
                        $principalAllocation,
                        $excess,
                        $allocated,
                        $paymntBreakDown['payment_amount'],
                        $paymntBreakDown['payment_date']
                    );

                    $this->updateAmortization(
                        $contract_no,
                        $due_date,
                        $updatedBalance,
                        $amortInterestAllocation,
                        $amortPrincipalAllocation,
                        $varAmorComplete
                    );

                    $amort_future_interest = 0;
                    $amort_future_principal = 0;

                    while ($excess > 0) {

                        $nextAmortization = MasterAmortization::where('contract_no', $contract_no)
                            ->where('completed', false)
                            ->where('due_date', '>', $due_date)
                            ->orderBy('due_date')
                            ->first();

                        if (!$nextAmortization) {
                            // No more amortizations to apply excess to
                            $paymntBreakDown['future_rent'] = $excess; // Store remaining excess
                            break;
                        }

                        $nxtAmortInt = $nextAmortization->balance_interest;
                        $nxtAmortPrincipal = $nextAmortization->balance_principal;
                        $nxtAmortDeductable = $excess;

                        // Apply excess to next amortization
                        if ($excess >= $nextAmortization->balance_payment) {
                            // Excess can cover full balance
                            $excess -= $nextAmortization->balance_payment;

                            if ($nxtAmortDeductable >= $nxtAmortInt) {
                                $amort_future_interest += $nxtAmortInt;
                                $nxtAmortDeductable -= $nxtAmortInt;
                                $nxtAmortInt = 0;
                                if ($nxtAmortDeductable >= $nxtAmortPrincipal) {
                                    $amort_future_principal += $nxtAmortPrincipal;
                                    $nxtAmortDeductable -= $nxtAmortPrincipal;
                                    $nxtAmortPrincipal = 0;
                                }
                                else{
                                    $amort_future_principal += $nxtAmortDeductable;
                                    $nxtAmortPrincipal -= $nxtAmortDeductable;
                                    $nxtAmortDeductable = 0;
                                }
                            }
                            elseif ($nxtAmortDeductable < $nxtAmortInt) {
                                $amort_future_principal += $nxtAmortDeductable;
                                $nxtAmortInt -= $nxtAmortDeductable;
                                $nxtAmortDeductable = 0;
                            }

                            if ($nxtAmortDeductable >= $nxtAmortPrincipal) {
                                $nxtAmortDeductable -= $nxtAmortPrincipal;
                                $nxtAmortPrincipal = 0;
                            }
                            elseif($nxtAmortDeductable < $nxtAmortPrincipal){
                                $nxtAmortPrincipal -=$nxtAmortDeductable;
                                $nxtAmortDeductable = 0;
                            }

                            $updatedBalance = 0;
                            $varAmorComplete = 1;
                        } else {
                            // Excess partially covers balance
                            $updatedBalance = $nextAmortization->balance_payment - $excess;


                            if ($nxtAmortDeductable >= $nxtAmortInt) {
                                $amort_future_interest += $nxtAmortInt;
                                $nxtAmortDeductable -= $nxtAmortInt;
                                $nxtAmortInt = 0;
                                if ($nxtAmortDeductable >= $nxtAmortPrincipal) {
                                    $amort_future_principal += $nxtAmortPrincipal;
                                    $nxtAmortDeductable -= $nxtAmortPrincipal;
                                    $nxtAmortPrincipal = 0;
                                }
                                else{
                                    $amort_future_principal += $nxtAmortDeductable;
                                    $nxtAmortPrincipal -= $nxtAmortDeductable;
                                    $nxtAmortDeductable = 0;
                                }
                            }
                            elseif ($nxtAmortDeductable < $nxtAmortInt) {
                                $amort_future_interest += $nxtAmortDeductable;
                                $nxtAmortInt -= $nxtAmortDeductable;
                                $nxtAmortDeductable = 0;
                            }

                            if ($nxtAmortDeductable >= $nxtAmortPrincipal) {
                                $amort_future_principal += $nxtAmortPrincipal;
                                $nxtAmortDeductable -= $nxtAmortPrincipal;
                                $nxtAmortPrincipal = 0;
                            }
                            elseif($nxtAmortDeductable < $nxtAmortPrincipal){
                                $amort_future_principal += $nxtAmortDeductable;
                                $nxtAmortPrincipal -=$nxtAmortDeductable;
                                $nxtAmortDeductable = 0;
                            }

                            $excess = 0;
                            $varAmorComplete = 0;

                            $pymnt_id = $updateBreak->getData()->data->pymnt_id;

                            $this->updateBreakdownFuture(
                                $pymnt_id,
                                $amort_future_interest,
                                $amort_future_principal
                            );

                        }

                        // Update the next amortization schedule
                        $this->updateAmortization(
                            $contract_no,
                            $nextAmortization->due_date,
                            $updatedBalance,
                            $nxtAmortInt,
                            $nxtAmortPrincipal,
                            $varAmorComplete
                        );

                        // Update current due_date for next iteration
                        $due_date = $nextAmortization->due_date;
                    }


                    $prev_payment_date = $payment_date;
                    $prev_payment_id = $pymnt_id;
                }



                // Use $lastPayment as needed
                //return $lastPayment;
            } else {
                // No records found
                return null;
            }


        }
        else{
            $paymntBreakDownSchedule = $this->paymentBreakdownService->refreshPaymentBreakdown($contract_no);
            if($paymntBreakDownSchedule){

                $amortizationSchedules = $this->amortizationService->getOrGenerateAmortizationSchedule($contract_no);

                $allPaymentsProcessed = false;
                $carryOverExcess = 0;
                $excess = 0;
                $updatedBalance = 0;
                $varAmorComplete = 0;
                $prev_payment_date = '';
                $prev_payment_id = '';

                foreach ($paymntBreakDownSchedule as &$paymntBreakDown) {
                    $allocated = 0;

                    //echo '$allocated check : ' .  $paymntBreakDown['allocated'] . PHP_EOL;

                    //echo ' incoming $carryOverExcess : ' . $carryOverExcess . PHP_EOL;


                    if($carryOverExcess > 0 && $prev_payment_id){
                        $prevBreakSchedule = PaymentBreakdown::where('pymnt_id', $prev_payment_id)
                        ->where('contract_no', $contract_no)
                        ->first();
                        if($prevBreakSchedule){
                            $prevBreakSchedule->update([
                                'allocated' => 1,
                            ]);
                        }
                    }

                    $excess = $carryOverExcess;
                    $carryOverExcess = 0;

                    $amortizationSchedule = MasterAmortization::where('contract_no', $contract_no)
                    ->where('completed', false)
                    ->first();

                    $contractID = $contract_no;

                    $deductable = $paymntBreakDown['payment_amount'];
                    $pymnt_id = $paymntBreakDown['pymnt_id'];
                    $payment_date = Carbon::parse($paymntBreakDown['payment_date']);
                    $due_date = Carbon::parse($amortizationSchedule->due_date);

                    //echo $due_date;


                    $interestAllocation = $amortizationSchedule->balance_interest;
                    $principalAllocation = $amortizationSchedule->balance_principal;



                    $overDueInterest = 0;
                    $overdueRent = 0;

                    $amortInterestAllocation = $amortizationSchedule->balance_interest;
                    $amortPrincipalAllocation = $amortizationSchedule->balance_principal;

                    $updatedBalance = $amortizationSchedule->balance_payment;


                    //echo $updatedBalance;
                    //$excess = $this->$excess;
                    if($deductable > $updatedBalance){
                        $allocated = 1;
                    }


                    if ($payment_date <= $due_date) {
                        $overDueInterest = 0;
                        $overdueRent = 0;
                        // Apply current payment
                        if ($excess > 0) {
                            if($excess < $updatedBalance){
                                $updatedBalance -= $excess;
                            } else if($excess >= $updatedBalance){
                                $updatedBalance = 0;
                            }
                            if ($excess >= $interestAllocation) {
                                $excess -= $interestAllocation;
                            }
                            else if ($excess < $interestAllocation) {
                                $amortInterestAllocation -= $excess;
                                $interestAllocation -= $excess;
                                $excess = 0;
                            }

                            if ($excess >= $principalAllocation) {
                                $excess -= $principalAllocation;
                            }
                            else if ($excess < $principalAllocation) {
                                $amortPrincipalAllocation -= $excess;
                                $principalAllocation -= $excess;
                                $excess = 0;
                            }
                        }

                        if ($deductable > 0) {
                            if($updatedBalance >= $deductable){
                                $updatedBalance -= $deductable;
                            } else if($updatedBalance < $deductable){
                                $updatedBalance = 0;
                            }

                            if ($deductable >= $interestAllocation) {
                                $deductable -= $interestAllocation;
                                $amortInterestAllocation = 0;
                            }
                            else if ($deductable < $interestAllocation) {
                                $interestAllocation = $deductable;
                                $amortInterestAllocation -= $deductable;
                                $deductable = 0;
                            }

                            if ($deductable >= $principalAllocation) {
                                $deductable -= $principalAllocation;
                                $amortPrincipalAllocation = 0;
                            }
                            else if ($deductable < $principalAllocation) {
                                $amortPrincipalAllocation -= $deductable;
                                $principalAllocation = $deductable;
                                $deductable = 0;
                            }
                        }

                        if ($deductable > 0) {
                            $excess += $deductable;
                            $carryOverExcess = $excess;
                        }
                    }

                    elseif($payment_date > $due_date){

                        $interestAllocation = 0;
                        $principalAllocation = 0;

                        $prev_payment_date = Carbon::parse($prev_payment_date);
                        $currentTimestamp = Carbon::parse($payment_date)
                        ->hour(0)
                        ->minute(0)
                        ->second(0);
                        if($prev_payment_date){
                            if($prev_payment_date <= $due_date){
                                $DiffV = ($currentTimestamp)->diffInDays($due_date);
                            }
                            elseif($prev_payment_date > $due_date){
                                $DiffV = ($currentTimestamp)->diffInDays($prev_payment_date);
                            }
                            $overdueRent = round($updatedBalance, 2);
                            $overDueInterest = $DiffV * $contractDefIntRate * $overdueRent / 100;
                            $overDueInterest = round($overDueInterest, 2);
                            $updatedBalance += $overDueInterest;

                            if($deductable > $updatedBalance){
                                $allocated = 1;
                            }

                            // Apply current payment
                            if ($excess > 0) {
                                if($updatedBalance >= $excess){
                                    $updatedBalance = 0;
                                }
                                elseif($excess < $updatedBalance){
                                    $updatedBalance -= $excess;
                                }

                                if ($excess >= $overDueInterest) {
                                    $excess -= $overDueInterest;
                                }
                                elseif ($excess < $overDueInterest) {
                                    //$amortInterestAllocation -= $excess;
                                    $overDueInterest -= $excess;
                                    $excess = 0;
                                }
                                if ($excess >= $overdueRent) {
                                    $excess -= $overdueRent;
                                }
                                elseif ($excess < $overdueRent) {
                                    //$amortPrincipalAllocation -= $excess;
                                    $overdueRent -= $excess;
                                    $excess = 0;
                                }
                            }

                            if ($deductable > 0) {

                                if($updatedBalance > $deductable){
                                    $updatedBalance -= $deductable;
                                } elseif($deductable >= $updatedBalance){
                                    $updatedBalance = 0;
                                    $allocated = 1;
                                }

                                if($deductable >= $overDueInterest) {
                                    $deductable -= $overDueInterest;
                                    if ($deductable >= $overdueRent) {
                                        $deductable -= $overdueRent;
                                    }
                                    elseif ($deductable < $overdueRent) {
                                        $overdueRent -= $deductable;
                                        $deductable = 0;
                                    }
                                }
                                elseif ($deductable < $overDueInterest) {
                                    $overDueInterest -= $deductable;
                                    $deductable = 0;
                                }
                            }

                            if ($deductable > 0) {
                                $excess += $deductable;
                                $carryOverExcess = $excess;
                                $allocated = 0;
                            }
                        }

                    }

                    if($updatedBalance <= 0){
                        $varAmorComplete = 1;
                    }
                    else{
                        $varAmorComplete = 0;
                    }

                    $allocated = 1;


                    $updateBreak = $this->updateBreakdown(
                        $contract_no,
                        $pymnt_id,
                        $overDueInterest,
                        $overdueRent,
                        $interestAllocation ,
                        $principalAllocation,
                        $excess,
                        $allocated,
                        $paymntBreakDown['payment_amount'],
                        $paymntBreakDown['payment_date']
                    );


                    $this->updateAmortization(
                        $contract_no,
                        $due_date,
                        $updatedBalance,
                        $amortInterestAllocation,
                        $amortPrincipalAllocation,
                        $varAmorComplete
                    );

                    $prev_payment_date = $payment_date;
                    $prev_payment_id = $pymnt_id;

                    $amort_future_interest = 0;
                    $amort_future_principal = 0;
                    $i = 1;

                    //echo 'after deduct $carryOverExcess : ' . $carryOverExcess . PHP_EOL;

                    if($carryOverExcess > 0){
                        while ($carryOverExcess > 0) {

                            $amortInterestAllocation = 0;
                            $amortPrincipalAllocation = 0;

                            $nextAmortization = MasterAmortization::where('contract_no', $contract_no)
                                ->where('completed', false)
                                ->where('due_date', '>', $due_date)
                                ->orderBy('due_date')
                                ->first();

                            if (!$nextAmortization) {
                                // No more amortizations to apply excess to
                                $paymntBreakDown['future_rent'] = $carryOverExcess; // Store remaining excess
                                break;
                            }

                            $nxtAmortInt = $nextAmortization->balance_interest;
                            $nxtAmortPrincipal = $nextAmortization->balance_principal;
                            $nxtAmortBalance = $nextAmortization->balance_payment;
                            $nxtAmortdue_date = $nextAmortization->due_date;

                            $amortPrincipalAllocation = $nextAmortization->balance_principal;
                            $amortInterestAllocation = $nextAmortization->balance_interest;

                            if($carryOverExcess >= $nxtAmortBalance){
                                $nxtAmortBalance = 0;
                                $amort_future_interest += $nxtAmortInt;
                                $amortInterestAllocation = 0;
                                $carryOverExcess -= $nxtAmortInt;
                                if($carryOverExcess >= $nxtAmortPrincipal){
                                    $amort_future_principal += $nxtAmortPrincipal;
                                    $amortPrincipalAllocation = 0;
                                    $carryOverExcess -= $nxtAmortPrincipal;
                                }
                                else{
                                    $amort_future_principal += $excess;
                                    $nxtAmortPrincipal -= $excess;
                                    $amortPrincipalAllocation = $nxtAmortPrincipal;
                                    $carryOverExcess = 0;
                                }

                                $varAmorComplete = 1;
                            }
                            else{
                                $amort_future_interest += $carryOverExcess;
                                $nxtAmortInt -= $carryOverExcess;
                                $nxtAmortBalance -= $carryOverExcess;

                                $amortInterestAllocation = $nxtAmortInt;
                                $carryOverExcess = 0;
                                $varAmorComplete = 0;
                            }



                            // Update current due_date for next iteration

                            $pymnt_id = $updateBreak->getData()->data->pymnt_id;

                            $this->updateBreakdownFuture(
                                $pymnt_id,
                                $amort_future_interest,
                                $amort_future_principal
                            );

                            $this->updateAmortization(
                                $contract_no,
                                $nxtAmortdue_date,
                                $nxtAmortBalance,
                                $amortInterestAllocation,
                                $amortPrincipalAllocation,
                                $varAmorComplete
                            );




                            $i++;

                            //$carryOverExcess = 0;
                        }
                    }

                }


            }
        }

    }

    public function updateBreakdown($contract_no, $pymnt_id, $overInt, $overRent, $curInt,  $curRent, $excessAmnt, $allocated ,$payment_amount, $payment_date){
        $breakSchedule = PaymentBreakdown::where('contract_no', $contract_no)
        ->where('pymnt_id', $pymnt_id)
        ->first();

        if (!($contract_no && $pymnt_id)) {
            return response()->json([
                'status' => 404,
                'message' => 'Contract ID or Payment ID not provided'
            ], 404);
        }

        // Use updateOrCreate to handle both cases
        $breakSchedule = PaymentBreakdown::updateOrCreate(
            [
                'contract_no' => $contract_no,
                'pymnt_id' => $pymnt_id
            ],
            [
                'overdue_interest' => $overInt ?? 0,
                'overdue_rent' => $overRent ?? 0,
                'current_interest' => $curInt ?? 0,
                'current_rent' => $curRent ?? 0,
                'future_rent' => isset($excessAmnt) ? abs($excessAmnt) : 0,
                'allocated' => $allocated ?? false,
                // Include other required fields for creation
                'payment_amount' => $payment_amount ?? 0, // Example additional field
                'payment_date' => $payment_date ?? now() // Example additional field
            ]
        );

        return response()->json([
            'status' => 200,
            'message' => 'Schedule '.($breakSchedule->wasRecentlyCreated ? 'created' : 'updated').' successfully!',
            'data' => $breakSchedule
        ], 200);

    }

    public function updateBreakdownFuture(
        $pymnt_id,
        $future_interest,
        $future_principal
        ){
    $brSchedule = PaymentBreakdown::where('pymnt_id', $pymnt_id)
    ->first();

    $brSchedule->update([
            'future_interest' => $future_interest ?? 0,
            'future_principal' => $future_principal ?? 0,
        ]);
    return response()->json([
            'status' => 200,
            'message' => 'Schedule updated successfully!'
        ], 200);
    }

    public function updateAmortization(
        $contract_no,
        $due_date,
        $balanceUpDate,
        $interestAllocation,
        $principalAllocation,
        $varAmorComplete
        ){
            $amSchedule = MasterAmortization::where('contract_no', $contract_no)
            ->where('due_date', $due_date)
            ->first();
            if(!($contract_no && $due_date)){
                return response()->json([
                    'status' => 404,
                    'message' => 'Contract ID or Due Date not provided'
                ], 404);
        }
        else{
            if($balanceUpDate <= 0){
                $amSchedule->update([
                    'balance_payment' => 0,
                    'balance_interest' => 0,
                    'balance_principal' => 0,
                    'excess' => abs($balanceUpDate),
                    'completed' => $varAmorComplete
                ]);
            }else{
                $amSchedule->update([
                    'balance_payment' => abs($balanceUpDate),
                    'balance_interest' =>abs($interestAllocation),
                    'balance_principal' => abs($principalAllocation),
                    'excess' => 0,
                    'completed' => $varAmorComplete
                ]);
            };

            return response()->json([
                'status' => 200,
                'message' => 'Schedule updated successfully!'
            ], 200);
        }
    }
}
