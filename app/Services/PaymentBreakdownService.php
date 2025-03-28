<?php

namespace App\Services;
use App\Models\PaymentBreakdown;

class PaymentBreakdownService
{
        /**
     * Save payment breakdown details.
     *
     * @param string $pymnt_id
     * @param float $overdue_amount
     * @param float $overdue_interest
     * @param float $current_interest
     * @param float $current_principal
     * @param float $excess
     * @return void
     */
    public function savePaymentBreakdown(
        $pymnt_id,
        $contract_no,
        $overdue_amount,
        $overdue_interest,
        $current_interest,
        $current_principal,
        $future_rent,
        $excess
    ) {
        PaymentBreakdown::create([
            'pymnt_id' => $pymnt_id,
            'contract_no' => $contract_no,
            'overdue_rent' => $overdue_amount,
            'overdue_interest' => $overdue_interest,
            'current_interest' => $current_interest,
            'current_rent' => $current_principal,
            'future_rent' => $future_rent,
            'excess' => $excess,
        ]);

        return $current_principal;
    }

    /**
     * Delete Breakdowns for a contract.
     *
     * @param Contract $contract
     * @return void
     */
    public function deletePaymentBreakdowns($contract_no)
    {
        // Step 1: Delete all existing rows for the contract
        PaymentBreakdown::where('contract_no', $contract_no)->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Breakdowns Removed from contract successfully'
        ], 200);

    }


    public function processBreakdownsFromJson(array $breakdownsArray)
    {
        $results = [
            'updated' => 0,
            'created' => 0,
            'errors' => []
        ];

        foreach ($breakdownsArray as $index => $breakdown) {
            try {
                // Find existing record
                $existing = PaymentBreakdown::where([
                    'pymnt_id' => $breakdown['pymnt_id'],
                    'contract_no' => $breakdown['contract_no']
                ])->first();

                if ($existing) {
                    // Update existing record
                    $existing->update([
                        'overdue_rent' => $breakdown['overdue_rent'],
                        'overdue_interest' => $breakdown['overdue_interest'],
                        'current_interest' => $breakdown['current_interest'],
                        'current_rent' => $breakdown['current_rent'],
                        'future_rent' => $breakdown['future_rent'],
                        'excess' => $breakdown['excess'],
                        'updated_at' => now()
                    ]);
                    $results['updated']++;
                } else {
                    // Create new record
                    PaymentBreakdown::create([
                        'pymnt_id' => $breakdown['pymnt_id'],
                        'contract_no' => $breakdown['contract_no'],
                        'overdue_rent' => $breakdown['overdue_rent'],
                        'overdue_interest' => $breakdown['overdue_interest'],
                        'current_interest' => $breakdown['current_interest'],
                        'current_rent' => $breakdown['current_rent'],
                        'future_rent' => $breakdown['future_rent'],
                        'excess' => $breakdown['excess'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    $results['created']++;
                }
            } catch (\Exception $e) {
                $results['errors'][$index] = [
                    'error' => $e->getMessage(),
                    'data' => $breakdown
                ];
            }
        }

        return $results;
    }
}
?>
