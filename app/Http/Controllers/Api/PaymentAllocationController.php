<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\PaymentAllocationService;
use App\Models\PaymentBreakdown;

class PaymentAllocationController extends Controller
{


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

    public function allocatePayments($contract_no, PaymentAllocationService $service)
    {

        $service->allocatePayments($contract_no);

        return response()->json(['message' => 'Payments allocated successfully']);
    }


}
