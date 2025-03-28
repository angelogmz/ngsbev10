<?php

namespace App\Http\Controllers\Api;
use App\Models\Payment;
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

    public function refreshAmortizationSchedule($contract)
    {
        // Fetch or generate the amortization schedule
        $amortizationSchedule = $this->amortizationService->refreshAmortizationSchedule($contract);

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

    public function cleanPaymentBreakdowns($contract_no)
    {
        // Fetch or generate the amortization schedule
        $paymntBreakDown = $this->paymentBreakdownService->deletePaymentBreakdowns($contract_no);

        if($paymntBreakDown){
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

    public function allocatePayments($contract_no){

        $contractInterest = 0;

        // Fetch all unallocated payments for the given contract
        $payments = Payment::where('contract_no', $contract_no)
        ->where('allocated', false)
        ->orderBy('payment_date')
        ->get();

        //$this->cleanPaymentBreakdowns($contract_no);
        $contractDetails = $this->getContractDetails($contract_no);

        // Decode the JSON content
        $data = $contractDetails->getData();

        // Access the def_int_rate value
        $contractDefIntRate = $data->def_int_rate;

        $refreshAmortization = $this->refreshAmortizationSchedule($data->contract);
        if($refreshAmortization){
            $prev_payment_date = '';
            $currentTimestamp = '';
            $prev_excess = 0; // Track excess from the previous loop
            $future_rent = 0;
            $deductableAmount = 0; // Floating variable to track the amount to be deducted from the payment
            $carry_forward = 0; // Track carry forward amount

            // Initialize empty array before your loop starts
            $breakdowns = [];

            foreach ($payments as $payment) {
                $payment_amount = $payment->payment_amount;
                $payment_date = $payment->payment_date;
                // Initialize breakdown values
                $payment_id = $payment->pymnt_id;
                $overdue_amount = 0;
                $overdue_interest = 0;
                $current_interest = 0;
                $current_principal = 0;
                $this->$future_rent = 0;

                // Add previous excess to the current payment
                $payment_amount += $prev_excess;
                $deductableAmount = $payment_amount;



                if($prev_payment_date == ''){
                    $prev_payment_date = $payment_date;
                }
                else{
                    // Convert dates to timestamps
                    $prev_payment_date = Carbon::parse($prev_payment_date);
                    $currentTimestamp = Carbon::parse($payment_date)
                    ->hour(0)
                    ->minute(0)
                    ->second(0);
                    $date_diff=$prev_payment_date->diffInDays($currentTimestamp);
                }

                // Fetch all incomplete amortization schedules for the given contract
                $amortizationSchedules = MasterAmortization::where('contract_no', $contract_no)
                ->where('completed', false)
                ->orderBy('due_date')
                ->get();

                    // Loop through amortization schedules to allocate the payment
                    foreach ($amortizationSchedules as $amortization) {
                        $balanceUpDate = 0;
                        $varAmorComplete = 0;

                        if ($amortization->completed) {
                            continue; // Skip completed rows
                        }

                        $due_date = new Carbon($amortization->due_date);
                        $toBePaid = $amortization->balance_payment - $prev_excess;
                        $deductableAmount = 0;

                        if($currentTimestamp <= $due_date){
                            if($amortization->balance_payment < 0){
                                $prev_excess = abs($balanceUpDate);
                            }
                            $payment_amount += $prev_excess;
                            $deductableAmount = $payment_amount;
                            $balanceUpDate = $amortization->balance_payment - $payment_amount;

                            $current_interest = $amortization->interest;
                            $current_principal = $deductableAmount - $current_interest;

                            if($balanceUpDate <= 0){
                                $varAmorComplete = 1;
                                $this->$future_rent = abs($balanceUpDate);
                            }
                            else{
                                $varAmorComplete = 0;
                                $this->$future_rent = abs($balanceUpDate);
                            }

                            $this->updateAmortization($contract_no, $due_date, $balanceUpDate, $varAmorComplete);

                        }
                        else{
                            if($prev_payment_date <= $due_date){
                                $DiffV = ($currentTimestamp)->diffInDays($due_date);
                                $due_date = Carbon::parse($due_date);
                                $currentTimestamp = Carbon::parse($payment_date);
                                // Convert dates to timestamps
                                $contractArrears = $DiffV * $contractDefIntRate * $amortization->balance_payment / 100;
                                $contractArrears = round($contractArrears, 2);
                                $balanceUpDate = ($amortization->balance_payment + $contractArrears) - $payment_amount;
                                $overdue_interest = $contractArrears;
                                $overdue_amount = $payment_amount - $overdue_interest - $prev_excess;

                                if($balanceUpDate <= 0){
                                    $varAmorComplete = 1;
                                    $prev_excess = abs($balanceUpDate);
                                }
                                else{
                                    $varAmorComplete = 0;
                                }

                                $this->updateAmortization($contract_no, $due_date, $balanceUpDate, $varAmorComplete);
                            }
                            else{
                                $DiffV = ($currentTimestamp)->diffInDays($prev_payment_date);
                                $contractArrears = $DiffV * $contractDefIntRate * $amortization->balance_payment / 100;
                                $contractArrears = round($contractArrears, 2);
                                $balanceUpDate = ($amortization->balance_payment + $contractArrears) - $payment_amount;
                                $overdue_interest = $contractArrears;
                                if($balanceUpDate <= 0){
                                    $varAmorComplete = 1;
                                    $prev_excess = abs($balanceUpDate);
                                    $overdue_amount = $payment_amount - $overdue_interest - $prev_excess;
                                    $this->$future_rent = $prev_excess;
                                    $balanceUpDate = 0;
                                }
                                else{
                                    $varAmorComplete = 0;
                                    $prev_excess = 0;
                                    $overdue_amount = $payment_amount;
                                    $balanceUpDate = $balanceUpDate - $overdue_interest;
                                    $this->$future_rent = $prev_excess = abs($balanceUpDate);
                                }
                                $this->updateAmortization($contract_no, $due_date, $balanceUpDate, $varAmorComplete);
                            }


                        }

                        $prev_payment_date = $payment_date;
                        break;
                    }

                    // Save payment breakdown using the PaymentBreakdownService
                    // $this->paymentBreakdownService->savePaymentBreakdown(
                    //     $payment_id,
                    //     $data->contract->contract_no,
                    //     $overdue_amount,
                    //     $overdue_interest,
                    //     $current_interest,
                    //     $current_principal,
                    //     $this->$future_rent,
                    //     0
                    // );



                    // Collect the breakdown data
                    $breakdownData = [
                        'pymnt_id' => $payment_id,
                        'contract_no' => $data->contract->contract_no,
                        'overdue_rent' => $overdue_amount,
                        'overdue_interest' => $overdue_interest,
                        'current_interest' => $current_interest,
                        'current_rent' => $current_principal,
                        'future_rent' => $this->$future_rent,
                        'excess' => 0
                    ];

                    // Add to the collection array
                    $breakdowns[] = $breakdownData;




            }

            $results = $this->paymentBreakdownService->processBreakdownsFromJson($breakdowns);


            return response()->json([
                'status' => 200,
                'message' => 'Payments allocated successfully!'
            ], 200);

            return response()->json([
                'status' => 404,
                'message' => 'Payments allocation unsuccessfull'
            ], 404);
        }
        else{
            echo 'error !';
            return response()->json([
                'status' => 500,
                'message' => 'Unsuccessful!',
            ], 500);
        }

    }

    public function updateAmortization($contract_no, $due_date, $balanceUpDate, $varAmorComplete){
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
                    'completed' => $varAmorComplete,
                    'excess' => abs($balanceUpDate),
                ]);
            }else{
                $amSchedule->update([
                    'balance_payment' => $balanceUpDate,
                    'completed' => $varAmorComplete,
                    'excess' => 0,
                ]);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Schedule updated successfully!'
            ], 200);
        }
    }
}
