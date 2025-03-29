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

    public function allocatePayments($contractNo)
    {
        // Fetch or generate the amortization schedule
        $amortizationSchedule = $this->amortizationService->getOrGenerateAmortizationSchedule($contractNo);

        if (!$amortizationSchedule) {
            return response()->json([
                'status' => 404,
                'message' => 'Contract not found or unable to generate amortization schedule.',
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'contract_no' => $contractNo,
            'amortization_schedule' => $amortizationSchedule,
            'message' => 'Amortization schedule retrieved successfully!',
        ], 200);
    }
}
