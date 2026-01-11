<?php

namespace App\Services;
use App\Models\PaymentBreakdown;
use App\Models\MasterAmortization;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use App\Services\AmortizationService;
use App\Services\PaymentBreakdownService;
use Carbon\Carbon;



class PaymentAllocationService
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

    /**
     * Truncate all data before batch processing
    */
    public function truncateAllData()
    {
        // Disable foreign key checks temporarily if needed
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        PaymentBreakdown::truncate();
        MasterAmortization::truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function getContractDetails($contract_no): JsonResponse
    {
        $contractDetails = Contract::where('contract_no', $contract_no)->first();

        if (!$contractDetails) {
            return response()->json([
                'completed' => 404,
                'message' => 'Contract not found'
            ], 404);
        }

        return response()->json([
            'completed' => 200,
            'contract' => $contractDetails,
            'contract_no' => $contractDetails->contract_no,
            'def_int_rate' => $contractDetails->def_int_rate,
            'execution_date' => $contractDetails->loan_execution_date,
        ], 200);
    }

    public function getAmortizationSchedule($contract): JsonResponse
    {
        $amortizationSchedule = $this->amortizationService->getOrGenerateAmortizationSchedule($contract);

        if ($amortizationSchedule) {
            return response()->json([
                'completed' => 200,
                'message' => 'Successfully generated amortization schedule',
            ], 200);
        }

        return response()->json([
            'completed' => 404,
            'message' => 'Unable to generate amortization schedule',
        ], 404);
    }

    public function refreshPaymentBreakdown($contract_no): JsonResponse
    {
        $paymentBreakdowns = $this->paymentBreakdownService->refreshPaymentBreakdown($contract_no);

        if ($paymentBreakdowns) {
            return response()->json([
                'completed' => 200,
                'breakdown' => $paymentBreakdowns,
            ], 200);
        }

        return response()->json([
            'completed' => 404,
            'message' => 'Unable to refresh payment breakdown',
        ], 404);
    }

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


    public function allocatePayments($contract_no){
    // Add CORS headers for testing
    // header('Access-Control-Allow-Origin: http://localhost:5173');
    // header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    // header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        try {
            $contractDetails = $this->getContractDetails($contract_no);

            // Decode the JSON content
            $data = $contractDetails->getData();

            // Access the def_int_rate value
            $contractDefIntRate = $data->def_int_rate;
            $contrExecutionDate = $data->execution_date;


            //PaymentBreakdown::truncate();
            //MasterAmortization::truncate();

            $this->paymentBreakdownService->refreshPaymentBreakdown($contract_no);
            $this->amortizationService->getOrGenerateAmortizationSchedule($contract_no);


            $amortizationTable = MasterAmortization::where('contract_no', $contract_no)
            ->orderBy('due_date')
            ->get();

            $amortizationData = $amortizationTable->map(function ($item) {
                return [
                    'id' => $item->id,
                    'due_date' => $item->due_date,
                    'original_balance_payment' => (float) $item->payment, // Will be modified
                    'balance_payment' => (float) $item->balance_payment, // Will be modified
                    'current_interest' => (float) $item->balance_interest,
                    'current_rent' => (float) $item->balance_principal,
                    'overdue_int' => (float) $item->overdue_int,
                    'completed' => 0, // 0 = incomplete, 1 = complete
                ];
            })->toArray();

            $payments = PaymentBreakdown::where('contract_no', $contract_no)
            ->orderBy('payment_date')
            ->get();

            $paymentsData = $payments->map(function ($payment) {
                return [
                    'pymnt_id' => $payment->pymnt_id,
                    'payment_date' => $payment->payment_date,
                    'payment_amount' => (float) $payment->payment_amount,
                    'overdue_interest' => (float) $payment->overdue_interest ?? 0,
                    'overdue_rent' => (float) $payment->overdue_rent ?? 0,
                    'current_interest' => (float) $payment->current_interest ?? 0,
                    'current_rent' => (float) $payment->current_rent ?? 0,
                    'future_rent' => (float) $payment->future_rent ?? 0,
                    'allocated' => $payment->allocated ?? 0, // Assuming 'allocated' is a field in the PaymentBreakdown model
                ];
            })->toArray();



            foreach ($amortizationData as $index => &$scheduleItem) {
                // Get the current due date
                $dueDate = Carbon::parse($scheduleItem['due_date']);

                $filteredPayments = collect($paymentsData)
                ->filter(function ($payment) use ($index, $amortizationData, $dueDate) {
                    // First filter: only include unallocated payments
                    if (($payment['allocated'] ?? 0) === 1) {
                        return false; // Skip already allocated payments
                    }

                    $paymentDate = Carbon::parse($payment['payment_date']);

                    // For first index (0): get all payments <= due_date
                    if ($index === 0) {
                        return $paymentDate->lte($dueDate);
                    }

                    // For other indices: get payments between previous due_date and current due_date
                    $startDate = Carbon::parse($amortizationData[$index - 1]['due_date']);
                    return $paymentDate->between($startDate, $dueDate);
                })
                ->values()
                ->all();


                $today = Carbon::now();
                $dueDiff = $today->diffInDays($dueDate, false);

                if($dueDiff < 0) {
                    $previousRow = $amortizationData[$index - 1] ?? null;
                    $prevRowLastPaidOn = null;

                    if($previousRow){
                        $lastPaymentDue = '';
                        $lastPaidOn = '';
                        if($filteredPayments){
                            foreach ($filteredPayments as $key => &$payment) {
                            }
                        }
                        if($filteredPayments){
                            foreach ($filteredPayments as $key => &$payment) {
                                $paymentDate = Carbon::parse($payment['payment_date']);
                                $paymentAmount = $payment['payment_amount'];
                                $deductableamount = $paymentAmount;

                                if($payment['allocated'] == 0){
                                    $lastPaymentDue = $lastPaidOn ?: $amortizationData[$index - 1]['due_date'];
                                    $arrearsDays = abs($paymentDate->diffInDays($lastPaymentDue, false));


                                    if($amortizationData[$index - 1]['balance_payment'] > 0){
                                            $lastbalanceArrearsInt = round(
                                                (float) $amortizationData[$index - 1]['balance_payment']
                                                * abs((int) $arrearsDays)
                                                * (float) $contractDefIntRate
                                                / 100,
                                                2
                                            );

                                            $amortizationData[$index - 1]['overdue_int'] = $lastbalanceArrearsInt;
                                            if($deductableamount >= $lastbalanceArrearsInt){
                                                $payment['overdue_interest'] = $lastbalanceArrearsInt;
                                                $deductableamount -= $lastbalanceArrearsInt;
                                                if($deductableamount >= $amortizationData[$index - 1]['balance_payment']){
                                                    $payment['overdue_rent'] = $amortizationData[$index - 1]['balance_payment'];
                                                    $deductableamount -= $amortizationData[$index - 1]['balance_payment'];
                                                    $amortizationData[$index - 1]['balance_payment'] = 0;
                                                    $amortizationData[$index - 1]['completed'] = 1;
                                                } else {
                                                    $payment['overdue_rent'] = $deductableamount;
                                                    $amortizationData[$index - 1]['balance_payment'] -= $deductableamount;
                                                    $deductableamount = 0;
                                                }
                                            } else {
                                                $paymentsData['overdue_interest'] = $deductableamount;
                                                $deductableamount = 0;
                                            }
                                    }
                                    if($amortizationData[$index]['balance_payment'] > 0 && $deductableamount > 0){
                                        if($deductableamount >= $amortizationData[$index]['balance_payment']){
                                            $payment['current_rent'] = $amortizationData[$index]['balance_payment'];
                                            $deductableamount -= $amortizationData[$index]['balance_payment'];
                                            $amortizationData[$index]['balance_payment'] = 0;
                                            $amortizationData[$index]['completed'] = 1;
                                        } else {
                                            $payment['current_rent'] = $deductableamount;
                                            $amortizationData[$index]['balance_payment'] -= $deductableamount;
                                            $deductableamount = 0;
                                        }

                                    }


                                    $lastPaidOn  = $paymentDate;
                                    $prevRowLastPaidOn = $lastPaidOn;
                                    $payment['allocated'] = 1; // Mark as allocated

                                }



                            }
                            echo "payments after processing previous row before continuing to next:\n";

                            $key = array_search($payment['pymnt_id'], array_column($paymentsData, 'pymnt_id'));

                            if ($key !== false) {
                                $paymentsData[$key] = $payment; // Update existing
                            }
                            var_dump($paymentsData[$key]);


                        } else {
                            $curretDueDate = Carbon::parse($amortizationData[$index]['due_date']);
                            if($amortizationData[$index - 1]['balance_payment'] > 0){
                                $lastPaymntDue = $lastPaidOn ?: $amortizationData[$index - 1]['due_date'];
                                $arrearsDaysCount = abs($curretDueDate->diffInDays($lastPaymntDue, false));
                                $lastbalanceArrearsInt = round(
                                    (float) $amortizationData[$index - 1]['balance_payment']
                                    * abs((int) $arrearsDaysCount)
                                    * (float) $contractDefIntRate
                                    / 100,
                                    2
                                );
                                $amortizationData[$index - 1]['overdue_int'] = $lastbalanceArrearsInt;

                            }
                        }
                        continue;

                    }
                    else {
                        if (empty($filteredPayments)) {
                            continue;
                        }
                        else{
                          foreach ($filteredPayments as $key => &$payment) {
                                if($payment['allocated'] == 0){
                                    $amortizationData[$index]['balance_payment'] -= $payment['payment_amount'];
                                    $payment['current_interest'] -= $payment['payment_amount'];
                                    if($amortizationData[$index]['balance_payment'] <= 0){
                                        $amortizationData[$index]['excess']  = abs($amortizationData[$index]['balance_payment']);
                                        $amortizationData[$index]['balance_payment'] = 0;
                                        $amortizationData[$index]['completed'] = 1;
                                    }

                                    $payment['allocated'] = 1; // Mark as allocated

                                    $key = array_search($payment['pymnt_id'], array_column($paymentsData, 'pymnt_id'));

                                    if ($key !== false) {
                                        $paymentsData[$key] = $payment; // Update existing
                                    }
                                }
                            }
                        }
                    }

                }


                echo "-----------------------------------------------------------------------------------------------------\n";

            }

            unset($scheduleItem); // Unset reference to avoid accidental modifications


            foreach ($paymentsData as $pIndex => &$payment) {

                if (($payment['allocated'] ?? 0) == 1) {
                    continue;
                }

                $remainingPayment = (float) $payment['payment_amount'];

                foreach ($amortizationData as $aIndex => &$amortRow) {

                    // Skip completed amortization rows
                    if (($amortRow['completed'] ?? 0) == 1) {
                        continue;
                    }

                    if ($remainingPayment <= 0) {
                        break;
                    }

                    $balance = (float) $amortRow['balance_payment'];

                    // Nothing to pay in this row
                    if ($balance <= 0) {
                        $amortRow['completed'] = 1;
                        continue;
                    }

                    // Apply payment
                    if ($remainingPayment >= $balance) {
                        // Fully settle this amortization row
                        $remainingPayment -= $balance;
                        $amortRow['balance_payment'] = 0;
                        $amortRow['completed'] = 1;
                    } else {
                        // Partially settle this amortization row
                        $amortRow['balance_payment'] -= $remainingPayment;
                        $remainingPayment = 0;
                    }
                }

                unset($amortRow);

                // Mark payment as allocated
                $payment['allocated'] = 1;

                // Optional: track leftover payment
                if ($remainingPayment > 0) {
                    $payment['excess'] = $remainingPayment;
                }
            }

            unset($payment);


            $caseBalance = [];
            $caseOverDueInt = [];
            $caseCompleted = [];
            $dueDates = [];

            foreach ($amortizationData as $row) {
                //var_dump($row);
                $dueDate = $row['due_date'];
                $caseBalance[] = "WHEN due_date = '{$dueDate}' THEN {$row['balance_payment']}";
                $caseOverDueInt[] = "WHEN due_date = '{$dueDate}' THEN {$row['overdue_int']}";
                $caseCompleted[] = "WHEN due_date = '{$dueDate}' THEN {$row['completed']}";
                $dueDates[] = "'{$dueDate}'";
            }
            unset($row); // Unset the last element to avoid duplicate key error

            DB::update("
                UPDATE  master_amortization
                SET
                    balance_payment = CASE ".implode(' ', $caseBalance)." END,
                    overdue_int = CASE ".implode(' ', $caseOverDueInt)." END,
                    completed = CASE ".implode(' ', $caseCompleted)." END
                WHERE
                    contract_no = ? AND
                    due_date IN (".implode(',', $dueDates).")
            ", [$contract_no]);


            // Update payment breakdowns
            $caseInterest = [];
            $caseRent = [];
            $caseOverDueInterest = [];
            $caseOverDueRent = [];
            $caseFutureRent = [];
            $caseAllocated = [];
            $ids = [];


            foreach ($paymentsData as $data) {
                $overDueInterest = $data['overdue_interest'] ?? 0; // Default to 0 if not set
                $overDueRent = $data['overdue_rent'] ?? 0; // Default to 0 if not set
                $interest = $data['current_interest'] ?? 0; // Default to 0 if not set
                $rent = $data['current_rent'] ?? 0; // Default to 0 if not set
                $futureRent = $data['future_rent'] ?? 0; // Default to 0 if not set
                $allocated = $data['allocated'] ?? 0; // Default to 0 if not set

                $id = $data['pymnt_id'];
                $caseOverDueInterest[] = "WHEN pymnt_id = '{$id}' THEN {$overDueInterest}";
                $caseOverDueRent[] = "WHEN pymnt_id = '{$id}' THEN {$overDueRent}";
                $caseInterest[] = "WHEN pymnt_id = '{$id}' THEN {$interest}";
                $caseRent[] = "WHEN pymnt_id = '{$id}' THEN {$rent}";
                $caseFutureRent[] = "WHEN pymnt_id = '{$id}' THEN {$futureRent}";
                $caseAllocated[] = "WHEN pymnt_id = '{$id}' THEN {$allocated}";
                $ids[] = "'{$id}'";
            }
            unset($data); // Unset the last element to avoid duplicate key error

            DB::update("
                UPDATE payment_breakdowns
                SET
                    current_interest = CASE ".implode(' ', $caseInterest)." END,
                    current_rent = CASE ".implode(' ', $caseRent)." END,
                    overdue_interest = CASE ".implode(' ', $caseOverDueInterest)." END,
                    overdue_rent = CASE ".implode(' ', $caseOverDueRent)." END,
                    future_rent = CASE ".implode(' ', $caseFutureRent)." END,
                    allocated = CASE ".implode(' ', $caseAllocated)." END
                WHERE pymnt_id IN (".implode(',', $ids).")
            ");

        } catch (\Exception $e) {
            // Log error and skip
            return "Failed to allocate payments for contract {$contract_no}: " . $e->getMessage();
        }
    }

}
