<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentAllocationService;
use App\Models\Contract; // Assuming you have a Contract model
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;


class BatchPaymentAllocationController extends Controller
{
    protected $service;

    public function __construct(PaymentAllocationService $service)
    {
        $this->service = $service;
    }

    /**
     * Allocate payments for all contracts in chunks
     */
    public function allocateAllContracts()
    {
        $this->service->truncateAllData(); // clear old data

        $processed = 0;
        $skipped = 0;

        Contract::chunk(50, function ($contracts) use (&$processed, &$skipped) {
            foreach ($contracts as $contract) {
                try {
                    // Transaction per contract
                    DB::transaction(function () use ($contract) {
                        $this->service->allocatePayments($contract->contract_no);
                    });
                    $processed++;
                } catch (\Exception $e) {
                    Log::error("Skipping contract {$contract->contract_no}: {$e->getMessage()}");
                    $skipped++;
                    continue;
                }
            }
        });

        return response()->json([
            'message' => 'Batch processing completed',
            'processed' => $processed,
            'skipped' => $skipped
        ]);
    }

}
