<?php

namespace App\Services;
use App\Models\PaymentBreakdown;
use App\Models\Contract;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function getAllUnallocatedPaymentBreakdowns($contract_no)
    {
        // Check for unallocated payments
        $unAllocatedPayments = PaymentBreakdown::where('contract_no', $contract_no)
            ->where('allocated', false)
            ->get(); // Execute the query with get()

        if ($unAllocatedPayments->isNotEmpty()) {
            return $unAllocatedPayments;
        }
    }

       /**
     * Get or generate the amortization schedule for a given contract_no.
     *
     * @param string $contractNo
     * @return array|null
     */
    public function refreshPaymentBreakdown(string $contractNo): array
    {
        // Get all payments for the contract
        $allPayments = Payment::where('contract_no', $contractNo)
            ->orderBy('payment_date')
            ->get(['pymnt_id', 'contract_no','payment_amount','payment_date']);


        // Fields to reset to 0
        $zeroFields = [
            'overdue_interest' => 0,
            'overdue_rent' => 0,
            'current_interest' => 0,
            'current_rent' => 0,
            'future_rent' => 0,
            'excess' => 0
        ];

        $results = [];

        DB::transaction(function() use ($allPayments, $contractNo, $zeroFields, &$results) {
            foreach ($allPayments as $payment) {
                // Update or create the record

                $breakdown = PaymentBreakdown::updateOrCreate(
                    [
                        'pymnt_id' => $payment->pymnt_id,
                        'contract_no' => $contractNo
                    ],
                    array_merge([
                        'payment_amount' => $payment->payment_amount,
                        'payment_date' => Carbon::parse($payment->payment_date)->format('Y-m-d')
                    ], $zeroFields)
                );

                //var_dump($breakdown->toArray());
                $results[$payment->id] = $breakdown->toArray();
            }
        });

        // Get ALL payment breakdowns for this contract (not just the ones we updated)
        $allBreakdowns = PaymentBreakdown::where('contract_no', $contractNo)
            ->get()
            ->keyBy('pymnt_id')
            ->toArray();

        return $allBreakdowns;
    }

    public function filterMissingBreakdowns(string $contractNo): array{
        // Get all payment IDs from Payments table
        $paymentIds = Payment::where('contract_no', $contractNo)
        ->pluck('pymnt_id')
        ->toArray();

        // Get all payment IDs from PaymentBreakdown table
        $breakdownIds = PaymentBreakdown::where('contract_no', $contractNo)
        ->pluck('pymnt_id')
        ->toArray();

        // Find IDs that are in Payments but missing in PaymentBreakdown
        $missingIds = array_diff($paymentIds, $breakdownIds);


        // Get full records of missing payments
        // $missingPayments = Payment::whereIn('pymnt_id', $missingIds)
        // ->get(['pymnt_id', 'contract_no', 'payment_amount', 'payment_date']);

        $missingPayments = Payment::whereIn('pymnt_id', $missingIds)
        ->get(['pymnt_id', 'contract_no', 'payment_amount', 'payment_date'])
        ->toArray();

        //var_dump($missingPayments);

        // Return or process the missing payments
        return $missingPayments;
    }
}
?>
