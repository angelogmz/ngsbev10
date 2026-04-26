<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentAllocationService;
use App\Models\Contract;
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
     * cPanel-friendly with minimal dependencies
     */
    public function allocateAllContracts()
    {
        // Set execution limits for cPanel
        set_time_limit(300); // 5 minutes max
        ini_set('memory_limit', '512M');

        $processed = 0;
        $skipped = 0;
        $errors = [];

        try {
            // Check if truncate method exists before calling
            if (method_exists($this->service, 'truncateAllData')) {
                $this->service->truncateAllData();
            }
        } catch (\Exception $e) {
            //Log::warning("Truncate skipped: " . $e->getMessage());
        }

        // Get total contracts count first
        $totalContracts = 0;
        try {
            $totalContracts = Contract::count();
        } catch (\Exception $e) {
            Log::error("Failed to count contracts: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ], 500);
        }

        if ($totalContracts === 0) {
            return response()->json([
                'success' => true,
                'message' => 'No contracts found',
                'processed' => 0,
                'skipped' => 0
            ]);
        }

        // Process in smaller chunks for cPanel (25 instead of 50)
        $chunkSize = 25;
        $offset = 0;

        while ($offset < $totalContracts) {
            try {
                $contracts = Contract::skip($offset)->take($chunkSize)->get();

                foreach ($contracts as $contract) {
                    // Reset time limit for each contract
                    set_time_limit(60);

                    try {
                        // Simple transaction without complex error wrapping
                        DB::beginTransaction();

                        $result = $this->service->allocatePayments($contract->contract_no);

                        DB::commit();
                        $processed++;

                    } catch (\Exception $e) {
                        DB::rollBack();

                        $errorMsg = $e->getMessage();
                        //Log::error("Contract {$contract->contract_no} failed: " . $errorMsg);

                        $errors[] = [
                            'contract' => $contract->contract_no,
                            'error' => substr($errorMsg, 0, 200) // Limit error length
                        ];

                        $skipped++;
                        // Continue to next contract
                        continue;
                    }
                }

                $offset += $chunkSize;

            } catch (\Exception $e) {
                //Log::error("Chunk failed at offset {$offset}: " . $e->getMessage());
                $offset += $chunkSize; // Skip this chunk and continue
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Batch processing completed',
            'processed' => $processed,
            'skipped' => $skipped,
            'total' => $totalContracts,
            'errors' => count($errors) > 0 ? $errors : null
        ]);
    }

    /**
     * Allocate payments for a single contract
     * Simple and robust
     */
    public function allocateSingleContract($contract_no)
    {
        // Set execution limits
        set_time_limit(120);

        try {
            // Validate contract exists first
            $contract = Contract::where('contract_no', $contract_no)->first();

            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => "Contract {$contract_no} not found"
                ], 404);
            }

            DB::beginTransaction();

            $result = $this->service->allocatePayments($contract_no);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Contract {$contract_no} processed successfully",
                'data' => $result
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'contract' => $contract_no
            ], 500);
        }
    }

    /**
     * Process contracts in small batches via command line (cron friendly)
     * Best for cPanel cron jobs
     */
    public function allocateBatchCli($start = 0, $limit = 10)
    {
        // CLI mode detection
        $isCli = php_sapi_name() === 'cli';

        if (!$isCli) {
            // Allow web access with secret key
            $secret = request()->get('secret');
            if ($secret !== env('BATCH_SECRET', 'your-secret-key')) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
        }

        set_time_limit(300);

        $processed = 0;
        $failed = 0;

        try {
            $contracts = Contract::skip($start)->take($limit)->get();

            foreach ($contracts as $contract) {
                try {
                    DB::beginTransaction();
                    $this->service->allocatePayments($contract->contract_no);
                    DB::commit();
                    $processed++;

                    if ($isCli) {
                        echo "✓ Processed: {$contract->contract_no}\n";
                    }

                } catch (\Exception $e) {
                    DB::rollBack();
                    $failed++;

                    if ($isCli) {
                        echo "✗ Failed: {$contract->contract_no} - {$e->getMessage()}\n";
                    } else {
                        //Log::error("Failed: {$contract->contract_no} - {$e->getMessage()}");
                    }
                }
            }

        } catch (\Exception $e) {
            if ($isCli) {
                echo "Error: " . $e->getMessage() . "\n";
            }
            //Log::error("Batch error: " . $e->getMessage());
        }

        $result = [
            'success' => true,
            'start' => $start,
            'limit' => $limit,
            'processed' => $processed,
            'failed' => $failed
        ];

        if ($isCli) {
            echo json_encode($result) . "\n";
            return;
        }

        return response()->json($result);
    }
}
