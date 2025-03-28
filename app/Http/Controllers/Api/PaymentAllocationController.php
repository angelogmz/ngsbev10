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

    public function allocatePayments($contract_no){

        $contractInterest = 0;

        // Fetch all unallocated payments for the given contract
        $payments = Payment::where('contract_no', $contract_no)
        ->where('allocated', false)
        ->orderBy('payment_date')
        ->get();

        //$this->cleanPaymentBreakdowns($contract_no);
        $contractDetails = $this->getContractDetails($contract_no);

        return  $contractDetails;

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
