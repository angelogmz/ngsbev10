<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentAllocationService;
use App\Models\Contract;
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
            // Return error immediately so you can see it in API
            return response()->json([
                'success' => false,
                'message' => 'Truncate failed: ' . $e->getMessage(),
                'error_details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }

        // Get total contracts count first
        $totalContracts = 0;
        try {
            $totalContracts = Contract::count();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to count contracts: ' . $e->getMessage(),
                'error_details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }

        if ($totalContracts === 0) {
            return response()->json([
                'success' => true,
                'message' => 'No contracts found',
                'processed' => 0,
                'skipped' => 0,
                'total' => 0
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

                        // Collect error but continue processing
                        $errorMsg = $e->getMessage();
                        $errors[] = [
                            'contract' => $contract->contract_no,
                            'error' => $errorMsg,
                            'type' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine()
                        ];

                        $skipped++;
                        // Continue to next contract
                        continue;
                    }
                }

                $offset += $chunkSize;

            } catch (\Exception $e) {
                // Return chunk error immediately so you can see it
                return response()->json([
                    'success' => false,
                    'message' => 'Chunk failed at offset ' . $offset . ': ' . $e->getMessage(),
                    'error_details' => [
                        'offset' => $offset,
                        'chunk_size' => $chunkSize,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ],
                    'processed_so_far' => $processed,
                    'skipped_so_far' => $skipped
                ], 500);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Batch processing completed',
            'processed' => $processed,
            'skipped' => $skipped,
            'total' => $totalContracts,
            'errors' => count($errors) > 0 ? $errors : null,
            'error_count' => count($errors)
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
                'contract' => $contract_no,
                'error_details' => [
                    'type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
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
        $errors = [];

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

                    $errorDetails = [
                        'contract' => $contract->contract_no,
                        'error' => $e->getMessage(),
                        'type' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ];

                    $errors[] = $errorDetails;

                    if ($isCli) {
                        echo "✗ Failed: {$contract->contract_no} - {$e->getMessage()}\n";
                        echo "   File: {$e->getFile()}:{$e->getLine()}\n";
                    }
                }
            }

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();

            if ($isCli) {
                echo "Error: " . $errorMsg . "\n";
                echo "File: " . $e->getFile() . "\n";
                echo "Line: " . $e->getLine() . "\n";
                echo "Trace: " . $e->getTraceAsString() . "\n";
            }

            return response()->json([
                'success' => false,
                'message' => $errorMsg,
                'error_details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }

        $result = [
            'success' => true,
            'start' => $start,
            'limit' => $limit,
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors,
            'total_processed' => $processed + $failed
        ];

        if ($isCli) {
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            return;
        }

        return response()->json($result);
    }

    /**
     * Debug endpoint - Test single contract with full error details
     * Useful for debugging specific contracts
     */
    public function debugContract($contract_no)
    {
        set_time_limit(120);

        try {
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

            // Return full exception details for debugging
            return response()->json([
                'success' => false,
                'contract' => $contract_no,
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString())
                ]
            ], 500);
        }
    }

    /**
     * Test endpoint - Just echo any error immediately
     * Use this for quick debugging
     */
    public function testContract($contract_no)
    {
        set_time_limit(120);

        try {
            $contract = Contract::where('contract_no', $contract_no)->first();

            if (!$contract) {
                echo "ERROR: Contract {$contract_no} not found\n";
                return;
            }

            echo "Processing contract: {$contract_no}\n";

            DB::beginTransaction();
            $result = $this->service->allocatePayments($contract_no);
            DB::commit();

            echo "SUCCESS: Contract {$contract_no} processed\n";
            print_r($result);

        } catch (\Exception $e) {
            DB::rollBack();

            // Echo everything so you can see it in API response
            echo "========== ERROR DEBUG OUTPUT ==========\n";
            echo "Contract: {$contract_no}\n";
            echo "Error Message: " . $e->getMessage() . "\n";
            echo "Error Type: " . get_class($e) . "\n";
            echo "Error Code: " . $e->getCode() . "\n";
            echo "File: " . $e->getFile() . "\n";
            echo "Line: " . $e->getLine() . "\n";
            echo "========== STACK TRACE ==========\n";
            echo $e->getTraceAsString() . "\n";
            echo "========== END DEBUG OUTPUT ==========\n";
        }
    }
}
