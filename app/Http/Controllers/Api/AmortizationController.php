<?php

namespace App\Http\Controllers\Api;

use App\Services\AmortizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AmortizationController extends Controller
{
    protected $amortizationService;

    public function __construct(AmortizationService $amortizationService)
    {
        $this->amortizationService = $amortizationService;
    }

    /**
     * Retrieve the amortization schedule for a given contract_no.
     * If no schedule exists, generate and save it.
     *
     * @param string $contractNo
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAmortizationSchedule($contractNo)
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
