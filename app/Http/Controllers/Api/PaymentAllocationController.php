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
    function allocatePayment(
        float $amount,
        float &$updatedBalance,
        float &$interestAllocation,
        float &$principalAllocation,
        float &$amortInterestAllocation,
        float &$amortPrincipalAllocation,
        int &$allocated
    ): float {
        // First handle the updated balance
        if ($amount > 0) {
            if ($updatedBalance > $amount) {
                $updatedBalance -= $amount;
            } elseif ($amount >= $updatedBalance) {
                $updatedBalance = 0;
                $allocated = 1;
            }

            // Allocate to different components in priority order

            if ($amount >= $interestAllocation) {
                $amount -= $interestAllocation;

                if ($amount >= $principalAllocation) {
                    $amount -= $principalAllocation;
                } else {
                    $principalAllocation = $amount;
                    $amortPrincipalAllocation -= $amount;
                    $amount = 0;
                }
            } else {
                $interestAllocation = $amount;
                $amortInterestAllocation -= $amount;
                $amount = 0;
                $principalAllocation = 0;
            }
        }

        return $amount;
    }

    function processPaymentAllocations(
        float &$excess,
        float &$deductable,
        float &$updatedBalance,
        float &$interestAllocation,
        float &$principalAllocation,
        float &$amortInterestAllocation,
        float &$amortPrincipalAllocation,
        int &$allocated,
        float &$carryOverExcess
    ): void {
        // Process excess payment
        $excess = $this->allocatePayment(
            $excess,
            $updatedBalance,
            $interestAllocation,
            $principalAllocation,
            $amortInterestAllocation,
            $amortPrincipalAllocation,
            $allocated
        );

        // Process deductible payment
        $deductable = $this->allocatePayment(
            $deductable,
            $updatedBalance,
            $interestAllocation,
            $principalAllocation,
            $amortInterestAllocation,
            $amortPrincipalAllocation,
            $allocated
        );

        // Handle remaining deductible
        if ($deductable > 0) {
            $excess += $deductable;
            $carryOverExcess = $excess;
            $allocated = 0;
        }
    }


    function allocateLatePayment(
        float $amount,
        float &$updatedBalance,
        float &$overDueInterest,
        float &$overdueRent,
        float &$interestAllocation,
        float &$principalAllocation,
        float &$amortInterestAllocation,
        float &$amortPrincipalAllocation,
        int &$allocated
    ): float {
        // First handle the updated balance
        if ($amount > 0) {
            if ($updatedBalance > $amount) {
                $updatedBalance -= $amount;
            } elseif ($amount >= $updatedBalance) {
                $updatedBalance = 0;
                $allocated = 1;
            }

            // Allocate to different components in priority order
            if ($amount >= $overDueInterest) {
                $amount -= $overDueInterest;

                if ($amount >= $overdueRent) {
                    $amount -= $overdueRent;
                } else if ($amount < $overdueRent) {
                    $overdueRent = $amount;
                    $amortPrincipalAllocation -= $amount;
                    $amount = 0;
                    $interestAllocation = 0;
                    $principalAllocation = 0;
                }
            } elseif ($amount < $overDueInterest) {
                $overDueInterest = $amount;
                $amortInterestAllocation -= $amount;
                $amount = 0;
                $overdueRent = 0;
                $interestAllocation = 0;
                $principalAllocation = 0;
            }
        }
        return $amount;
    }

    function processLatePaymentAllocations(
        float &$excess,
        float &$deductable,
        float &$updatedBalance,
        float &$overDueInterest,
        float &$overdueRent,
        float &$interestAllocation,
        float &$principalAllocation,
        float &$amortInterestAllocation,
        float &$amortPrincipalAllocation,
        int &$allocated,
        float &$carryOverExcess
    ): void {

        // Process excess payment
        $excess = $this->allocateLatePayment(
            $excess,
            $updatedBalance,
            $overDueInterest,
            $overdueRent,
            $interestAllocation,
            $principalAllocation,
            $amortInterestAllocation,
            $amortPrincipalAllocation,
            $allocated
        );

        // Process deductible payment
        $deductable = $this->allocateLatePayment(
            $deductable,
            $updatedBalance,
            $overDueInterest,
            $overdueRent,
            $interestAllocation,
            $principalAllocation,
            $amortInterestAllocation,
            $amortPrincipalAllocation,
            $allocated
        );

        // Handle remaining deductible
        if ($deductable > 0) {
            $excess += $deductable;
            $carryOverExcess = $excess;
            $allocated = 0;
        }
    }

    public function allocatePayments($contract_no){

        PaymentBreakdown::truncate();
        MasterAmortization::truncate();


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
                    'allocated' => $lastBreakdown->allocated,
                    'excess' => $lastBreakdown->excess
                ];

                $filteredMissingBreakdowns = $this->paymentBreakdownService->filterMissingBreakdowns($contract_no);

                foreach ($filteredMissingBreakdowns as &$paymntBreakDown){

                    $excess = 0;
                    $carryOverExcess = 0;
                    $updatedBalance = 0;
                    $varAmorComplete = 0;
                    $prev_payment_date = $lastPayment['payment_date'];
                    $prev_payment_id = $lastPayment['pymnt_id'];
                    $prev_allocated = $lastPayment['allocated'];
                    $allocated = 0;

                    if($prev_allocated == 0){
                        $carryOverExcess = $lastPayment['future_rent'] + $lastPayment['excess'];
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

                    // Check if there are no incomplete amortizations left
                    $allCompleted = !MasterAmortization::where('contract_no', $contract_no)
                    ->where('completed', false)
                    ->exists();

                    if ($allCompleted) {

                        //$totalPayment = $carryOverExcess + $paymntBreakDown['payment_amount'];

                        // $recPrevBreakSchedule = PaymentBreakdown::where('contract_no', $contract_no)
                        // ->where('allocated', 0)
                        // ->first();

                        $totalPayment = $carryOverExcess;

                        if (isset($paymntBreakDown['pymnt_id'],$paymntBreakDown['payment_date'], $totalPayment, $contract_no)) {
                            $updateBreak = PaymentBreakdown::create([
                                'contract_no' => $contract_no,
                                'pymnt_id' => $paymntBreakDown['pymnt_id'],
                                'payment_amount' => $paymntBreakDown['payment_amount'],
                                'excess' => $paymntBreakDown['payment_amount'],
                                'payment_date' => $paymntBreakDown['payment_date'],
                            ]);
                        } else {
                            // Handle missing data (e.g., log an error or throw an exception)
                            throw new \Exception("Missing required payment breakdown data.");
                        }


                    // All amortizations for this contract are completed
                        return response()->json([
                            'status' => 200,
                            'message' => 'All amortizations are completed',
                        ], 200);
                    } else {
                    // There are still incomplete amortizations
                        // Proceed with normal logic
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
                            $this->processPaymentAllocations(
                                $excess,
                                $deductable,
                                $updatedBalance,
                                $interestAllocation,
                                $principalAllocation,
                                $amortInterestAllocation,
                                $amortPrincipalAllocation,
                                $allocated,
                                $carryOverExcess
                            );
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
                                $this->processLatePaymentAllocations(
                                    $excess,
                                    $deductable,
                                    $updatedBalance,
                                    $overDueInterest,
                                    $overdueRent,
                                    $interestAllocation,
                                    $principalAllocation,
                                    $amortInterestAllocation,
                                    $amortPrincipalAllocation,
                                    $allocated,
                                    $carryOverExcess
                                );
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

                        if($excess > 0){
                            $carryOverExcess = $excess;
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

                                if($carryOverExcess >= $nxtAmortBalance){
                                    $break_interest = $nxtAmortInt;
                                    $carryOverExcess -= $nxtAmortInt;
                                    $nxtAmortInt = 0;
                                    if($carryOverExcess >= $nxtAmortPrincipal){
                                        $break_principal = $nxtAmortPrincipal;
                                        $carryOverExcess -= $nxtAmortPrincipal;
                                        $nxtAmortPrincipal = 0;
                                    }
                                    else{
                                        $break_principal = $carryOverExcess;
                                        $nxtAmortPrincipal -= $carryOverExcess;
                                        $carryOverExcess = 0;
                                    }
                                }
                                elseif($carryOverExcess < $nxtAmortBalance){
                                    if($carryOverExcess >= $nxtAmortInt){
                                        $break_interest = $nxtAmortInt;
                                        $carryOverExcess -= $nxtAmortInt;
                                        $nxtAmortInt = 0;
                                        if($carryOverExcess >= $nxtAmortPrincipal){
                                            $break_principal = $nxtAmortPrincipal;
                                            $carryOverExcess -= $nxtAmortPrincipal;
                                            $nxtAmortPrincipal = 0;
                                        }
                                        else{
                                            $break_principal = $carryOverExcess;
                                            $nxtAmortPrincipal -= $carryOverExcess;
                                            $carryOverExcess = 0;
                                        }
                                    }
                                    else{
                                        $break_interest = $carryOverExcess;
                                        $nxtAmortInt -= $carryOverExcess;
                                        $carryOverExcess = 0;
                                    }
                                    $carryOverExcess = 0;
                                }

                                $nxtAmortBalance = $nxtAmortInt + $nxtAmortPrincipal;

                                if($carryOverExcess > 0){
                                    $break_future_rent = $carryOverExcess;
                                }

                                if($nxtAmortBalance <= 0){
                                    $varAmorComplete = 1;
                                }
                                else{
                                    $varAmorComplete = 0;
                                }

                                $this->updateBreakdownFuture(
                                    $pymnt_id,
                                    $break_interest,
                                    $break_principal,
                                    $break_future_rent
                                );

                                $this->updateAmortization(
                                    $contract_no,
                                    $nxtAmortdue_date,
                                    $nxtAmortBalance,
                                    $amortInterestAllocation,
                                    $amortPrincipalAllocation,
                                    $varAmorComplete
                                );

                            }
                        }



                        $prev_payment_date = $payment_date;
                        $prev_payment_id = $pymnt_id;
                    }

                }



                // Use $lastPayment as needed
                //return $lastPayment;
            } else {
                // No records found
                return null;
            }

            return response()->json([
                'status' => 200
            ], 200);

        }
        else{
            $paymntBreakDownSchedule = $this->paymentBreakdownService->refreshPaymentBreakdown($contract_no);
            if($paymntBreakDownSchedule){

                $this->amortizationService->getOrGenerateAmortizationSchedule($contract_no);

                $allPaymentsProcessed = false;
                $carryOverExcess = 0;
                $excess = 0;
                $updatedBalance = 0;
                $varAmorComplete = 0;
                $prev_payment_date = '';
                $prev_payment_id = '';

                foreach ($paymntBreakDownSchedule as &$paymntBreakDown) {
                    $allocated = 0;

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

                        $this->processPaymentAllocations(
                            $excess,
                            $deductable,
                            $updatedBalance,
                            $interestAllocation,
                            $principalAllocation,
                            $amortInterestAllocation,
                            $amortPrincipalAllocation,
                            $allocated,
                            $carryOverExcess
                        );

                    }

                    elseif($payment_date > $due_date){

                        $interestAllocation = 0;
                        $principalAllocation = 0;

                        //$prev_payment_date = Carbon::parse($prev_payment_date);
                        $prev_payment_date = $prev_payment_date ? Carbon::parse($prev_payment_date) : $payment_date;
                        $currentTimestamp = Carbon::parse($payment_date)
                        ->hour(0)
                        ->minute(0)
                        ->second(0);
                        if($prev_payment_date){
                            if($prev_payment_date <= $due_date){
                                $DiffV = ($currentTimestamp)->diffInDays($due_date);
                            }
                            elseif($prev_payment_date > $due_date){
                                $DiffV = ($payment_date)->diffInDays($due_date);
                            }

                            $overdueRent = round($updatedBalance, 2);
                            $overDueInterest = $DiffV * $contractDefIntRate * $overdueRent / 100;
                            $overDueInterest = round($overDueInterest, 2);
                            $updatedBalance += $overDueInterest;



                            if($deductable > $updatedBalance){
                                $allocated = 1;
                            }

                            $this->processLatePaymentAllocations(
                                $excess,
                                $deductable,
                                $updatedBalance,
                                $overDueInterest,
                                $overdueRent,
                                $interestAllocation,
                                $principalAllocation,
                                $amortInterestAllocation,
                                $amortPrincipalAllocation,
                                $allocated,
                                $carryOverExcess
                            );
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
                    $break_interest = 0;
                    $break_principal = 0;
                    $break_future_rent = 0;
                    $i = 1;

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

                            if($carryOverExcess >= $nxtAmortBalance){
                                $break_interest = $nxtAmortInt;
                                $carryOverExcess -= $nxtAmortInt;
                                $nxtAmortInt = 0;
                                if($carryOverExcess >= $nxtAmortPrincipal){
                                    $break_principal = $nxtAmortPrincipal;
                                    $carryOverExcess -= $nxtAmortPrincipal;
                                    $nxtAmortPrincipal = 0;
                                }
                                else{
                                    $break_principal = $carryOverExcess;
                                    $nxtAmortPrincipal -= $carryOverExcess;
                                    $carryOverExcess = 0;
                                }
                            }
                            elseif($carryOverExcess < $nxtAmortBalance){
                                if($carryOverExcess >= $nxtAmortInt){
                                    $break_interest = $nxtAmortInt;
                                    $carryOverExcess -= $nxtAmortInt;
                                    $nxtAmortInt = 0;
                                    if($carryOverExcess >= $nxtAmortPrincipal){
                                        $break_principal = $nxtAmortPrincipal;
                                        $carryOverExcess -= $nxtAmortPrincipal;
                                        $nxtAmortPrincipal = 0;
                                    }
                                    else{
                                        $break_principal = $carryOverExcess;
                                        $nxtAmortPrincipal -= $carryOverExcess;
                                        $carryOverExcess = 0;
                                    }
                                }
                                else{
                                    $break_interest = $carryOverExcess;
                                    $nxtAmortInt -= $carryOverExcess;
                                    $carryOverExcess = 0;
                                }
                                $carryOverExcess = 0;
                            }

                            $nxtAmortBalance = $nxtAmortInt + $nxtAmortPrincipal;

                            if($carryOverExcess > 0){
                                $break_future_rent = $carryOverExcess;
                            }

                            if($nxtAmortBalance <= 0){
                                $varAmorComplete = 1;
                            }
                            else{
                                $varAmorComplete = 0;
                            }

                            $this->updateBreakdownFuture(
                                $pymnt_id,
                                $break_interest,
                                $break_principal,
                                $break_future_rent
                            );

                            $this->updateAmortization(
                                $contract_no,
                                $nxtAmortdue_date,
                                $nxtAmortBalance,
                                $amortInterestAllocation,
                                $amortPrincipalAllocation,
                                $varAmorComplete
                            );

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
        $future_principal,
        $break_future_rent
        ){
            $brSchedule = PaymentBreakdown::where('pymnt_id', $pymnt_id)
            ->first();

            $brSchedule->update([
                'current_interest' => $future_interest ?? 0,
                'current_rent' => $future_principal ?? 0,
                'future_rent' => $break_future_rent ?? 0,
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

}
