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

                    if($previousRow){
                            if($amortizationData[$index]['balance_payment'] <= 0){
                                $totalPaidV =  0;
                                if($filteredPayments){
                                    $totalPaidV = array_sum(array_column($filteredPayments, 'payment_amount'));
                                    $amortizationData[$index]['balance_payment'] -= $totalPaidV;

                                    foreach ($filteredPayments as &$payment) {
                                        $payment['allocated'] = 1;

                                    }
                                };

                                if ($amortizationData[$index]['balance_payment'] < 0) {
                                    if (isset($amortizationData[$index + 1])) {
                                        $amortizationData[$index + 1]['balance_payment'] -= abs($amortizationData[$index]['balance_payment']);
                                        $amortizationData[$index]['balance_payment'] = 0;
                                    } else {
                                    }
                                }

                                $payment['current_interest'] = $amortizationData[$index]['current_interest'];
                                $payment['current_rent'] = $amortizationData[$index]['current_rent'];
                                $payment['future_rent'] = $payment['payment_amount'];

                                $key = array_search($payment['pymnt_id'], array_column($paymentsData, 'pymnt_id'));

                                if ($key !== false) {
                                    $paymentsData[$key] = $payment; // Update existing
                                }

                            } else {

                                $lastPaymentDue = '';
                                $lastPaidOn = '';
                                if($filteredPayments){
                                    foreach ($filteredPayments as $key => &$payment) {
                                        $paymentDate = Carbon::parse($payment['payment_date']);
                                        $paymentAmount = $payment['payment_amount'];
                                        $deductableamount = $paymentAmount;
                                        $deductableamountBreakDown = $paymentAmount;

                                        if($payment['allocated'] == 0){
                                            $lastPaymentDue = $lastPaidOn ?: $amortizationData[$index - 1]['due_date'];
                                            $arrearsDays = abs($paymentDate->diffInDays($lastPaymentDue, false));
                                            //var_dump($payment);
                                            if($amortizationData[$index - 1]['balance_payment'] > 0){
                                                if($arrearsDays >= 1){
                                                    $lastbalanceArrearsInt = round(
                                                        (float) $amortizationData[$index - 1]['balance_payment']
                                                        * abs((int) $arrearsDays)
                                                        * (float) $contractDefIntRate
                                                        / 100,
                                                        2
                                                    );

                                                    if($lastbalanceArrearsInt > $paymentAmount){
                                                        $payment['overdue_interest'] = $lastbalanceArrearsInt;
                                                    }
                                                    $amortizationData[$index - 1]['overdue_int'] += $lastbalanceArrearsInt;
                                                    $payment['overdue_interest'] =  $lastbalanceArrearsInt;
                                                    $deductableamountBreakDown -= $lastbalanceArrearsInt;

                                                    if($deductableamountBreakDown < $amortizationData[$index - 1]['balance_payment']){
                                                        $payment['overdue_rent'] = abs($deductableamountBreakDown);
                                                    } else{
                                                        $payment['overdue_rent'] = $amortizationData[$index - 1]['balance_payment'];
                                                    }

                                                    $deductableamount -= $amortizationData[$index - 1]['overdue_int'];
                                                    if($deductableamount < 0){
                                                        $amortizationData[$index - 1]['overdue_int'] = abs($deductableamount);
                                                    } else {
                                                        $amortizationData[$index - 1]['overdue_int'] = 0;
                                                        $amortizationData[$index - 1]['balance_payment'] -= $deductableamount;
                                                        if($amortizationData[$index - 1]['balance_payment'] < 0) {
                                                            $amortizationData[$index]['balance_payment'] -= abs($amortizationData[$index - 1]['balance_payment']);
                                                            $payment['future_rent'] = abs($amortizationData[$index - 1]['balance_payment']);
                                                            $amortizationData[$index - 1]['balance_payment'] = 0;
                                                        }
                                                    }
                                                }
                                                else{

                                                    if($amortizationData[$index - 1]['balance_payment'] > 0){
                                                        $amortizationData[$index - 1]['balance_payment'] -= $paymentAmount;
                                                        if($amortizationData[$index - 1]['balance_payment'] < 0) {
                                                            $amortizationData[$index]['balance_payment'] += abs($amortizationData[$index - 1]['balance_payment']);
                                                            $payment['future_rent'] = abs($amortizationData[$index - 1]['balance_payment']);
                                                        } else {
                                                            $payment['current_rent'] = $paymentAmount;
                                                        }
                                                    }
                                                }
                                                $payment['allocated'] = 1; // Mark as allocated
                                            } else {

                                                if($payment){
                                                    $breakableVal = $paymentAmount;
                                                    if($payment['allocated'] == 1){
                                                        continue; // Skip already allocated payments
                                                    } else {
                                                        // Determine the maximum rent that can be allocated

                                                        $payment['current_interest'] = min($breakableVal, $amortizationData[$index]['current_interest']);
                                                        $breakableVal -= $payment['current_interest'];

                                                        // Only allocate rent if interest was fully covered
                                                        if ($payment['current_interest'] == $amortizationData[$index]['current_interest']) {
                                                            $payment['current_rent'] = min($breakableVal, $amortizationData[$index]['current_rent']);
                                                        } else {
                                                            $payment['current_rent'] = 0;
                                                }


                                                        $amortizationData[$index]['balance_payment'] -= abs($paymentAmount);
                                                        if($amortizationData[$index]['balance_payment'] < 0) {
                                                            $payment['future_rent'] = abs($amortizationData[$index]['balance_payment']);
                                                            $remainingAmount = abs($amortizationData[$index]['balance_payment']);
                                                            $i = $index + 1;
                                                            // Loop through subsequent payments until we've distributed the full amount
                                                            while ($remainingAmount > 0 && isset($amortizationData[$i])) {
                                                                // Calculate how much we can deduct from this payment
                                                                $deductible = min($remainingAmount, $amortizationData[$i]['balance_payment']);
                                                                // Reduce both the current payment and the remaining amount
                                                                $amortizationData[$i]['balance_payment'] -= $deductible;
                                                                $remainingAmount -= $deductible;
                                                                $i++;
                                                            }
                                                            // $amortizationData[$index + 1]['balance_payment'] -= abs($amortizationData[$index]['balance_payment']);
                                                            // $payment['future_rent'] = abs($amortizationData[$index]['balance_payment']);
                                                            $amortizationData[$index]['balance_payment'] = 0;
                                                        }
                                                        $payment['allocated'] = 1; // Mark as allocated
                                                    }

                                                } else {
                                                    continue;
                                                }

                                            }

                                            $lastPaidOn = $paymentDate->toDateString();

                                            $key = array_search($payment['pymnt_id'], array_column($paymentsData, 'pymnt_id'));

                                            if ($key !== false) {
                                                $paymentsData[$key] = $payment; // Update existing
                                            }
                                        }



                                    }
                                    if($amortizationData[$index - 1]['balance_payment'] > 0){
                                        $frontArrearsDays = abs(Carbon::parse($amortizationData[$index]['due_date'])->diffInDays($lastPaidOn, false));
                                        $amortizationData[$index]['balance_payment'] += $amortizationData[$index - 1]['balance_payment'] + round($amortizationData[$index - 1]['balance_payment']*abs($frontArrearsDays)*$contractDefIntRate/100, 2);
                                        $amortizationData[$index - 1]['balance_payment'] = 0;
                                    }
                                }
                                else{
                                    $arrearsDays = abs(Carbon::parse($amortizationData[$index]['due_date'])->diffInDays($amortizationData[$index - 1]['due_date'], false));
                                    $lastbalanceArrearsInt = round(
                                        (float) $amortizationData[$index - 1]['balance_payment']
                                        * abs($arrearsDays)
                                        * (float) $contractDefIntRate / 100,
                                        2
                                    );
                                    $amortizationData[$index]['overdue_int'] += $lastbalanceArrearsInt + $amortizationData[$index - 1]['overdue_int'];
                                    $amortizationData[$index]['balance_payment'] += $amortizationData[$index - 1]['balance_payment'];
                                    $amortizationData[$index - 1]['balance_payment'] = 0;
                                }
                            }



                    }   else {
                        if($filteredPayments){
                            $totalPaid = 0;
                            foreach ($filteredPayments as $key => &$payment) {

                                $breakableAmount = $payment['payment_amount'];
                                $breakCurrentRent = 0;
                                $breakCurrentInt = 0;
                                $amortCurInt = $amortizationData[$index]['current_interest'];
                                $amortCurRent = $amortizationData[$index]['current_rent'];

                                if($breakableAmount > 0){
                                    if($breakableAmount >= $amortCurInt){
                                        $breakCurrentInt = $amortCurInt;
                                        $breakableAmount -= $amortCurInt;
                                    } else {
                                        $breakCurrentInt = $breakableAmount;
                                        $breakableAmount = 0;
                                    }

                                    if($breakableAmount >= $amortCurRent){
                                        $breakCurrentRent = $amortCurRent;
                                        $breakableAmount -= $amortCurRent;
                                    } else {
                                        $breakCurrentRent = $breakableAmount;
                                        $breakableAmount = 0;
                                    }
                                }

                                $payment['current_interest'] = $breakCurrentInt;
                                $payment['current_rent'] = $breakCurrentRent;



                                $totalPaid += $payment['payment_amount'];
                            }
                            $amortizationData[$index]['balance_payment'] -= $totalPaid;
                            if($amortizationData[$index]['balance_payment'] < 0) {
                                $payment['future_rent'] += abs($amortizationData[$index]['balance_payment']);
                                $amortizationData[$index + 1]['balance_payment'] -= abs($amortizationData[$index]['balance_payment']);
                                $amortizationData[$index]['balance_payment'] = 0;
                            }
                            $payment['allocated'] = 1; // Mark as allocated



                            $key = array_search($payment['pymnt_id'], array_column($paymentsData, 'pymnt_id'));

                            if ($key !== false) {
                                $paymentsData[$key] = $payment; // Update existing
                            }

                        }
                        else{
                            foreach ($paymentsData as $pymnt) {
                                $daysDifference = Carbon::parse($pymnt['payment_date'])->diffInDays($dueDate, false);
                                if($daysDifference < 0){
                                    $arrearsDays = abs($daysDifference);
                                    $lastbalanceArrearsInt = round((float)$amortizationData[$index]['balance_payment']*abs($arrearsDays)*$contractDefIntRate/100, 2);
                                    $tobePaid = $amortizationData[$index]['balance_payment'] + $lastbalanceArrearsInt;
                                    $amortizationData[$index]['balance_payment'] = abs($amortizationData[$index]['balance_payment'] -= $tobePaid);

                                    $pymnt['overdue_interest'] = $lastbalanceArrearsInt;
                                    $pymnt['overdue_rent'] = $pymnt['payment_amount'] - $lastbalanceArrearsInt;
                                    $pymnt['allocated'] = 1; // Mark as allocated

                                    $key = array_search($pymnt['pymnt_id'], array_column($paymentsData, 'pymnt_id'));

                                    if ($key !== false) {
                                        $paymentsData[$key] = $pymnt; // Update existing
                                    }
                                }
                            }


                        }
                    }

                }

            }

            unset($scheduleItem); // Unset reference to avoid accidental modifications

            // Handle payments after the LAST amortization due date
            $lastDueDate = Carbon::parse(end($amortizationData)['due_date']);

            // Store indices of payments after last amortization
            $paymentIndicesAfterLastAmortization = [];

            // First pass: identify which payments to process and store their indices
            foreach ($paymentsData as $index => $payment) {
                $paymentDate = Carbon::parse($payment['payment_date']);
                if ($paymentDate->greaterThanOrEqualTo($lastDueDate)) {
                    $paymentIndicesAfterLastAmortization[] = [
                        'index' => $index,
                        'payment_date' => $paymentDate,
                        'payment_amount' => $payment['payment_amount']
                    ];
                }
            }

            // Sort by payment date
            usort($paymentIndicesAfterLastAmortization, function($a, $b) {
                return $a['payment_date'] <=> $b['payment_date'];
            });

            if(!empty($paymentIndicesAfterLastAmortization)){
                // Get the last amortization item reference
                $lastAmortizationItem = &$amortizationData[count($amortizationData) - 1];
                $lastAmortizationDueDate = Carbon::parse($lastAmortizationItem['due_date']);
                $lastAmortizationIndex = count($amortizationData) - 1;

                foreach ($paymentIndicesAfterLastAmortization as $paymentInfo) {
                    $index = $paymentInfo['index'];
                    $paymentDate = $paymentInfo['payment_date'];
                    $paymentAmount = $paymentInfo['payment_amount'];
                    $remainingAmount = $paymentAmount;
                    $lastPaid = $lastPaid ?? $lastAmortizationDueDate;


                    $daysDifference = $lastPaid->diffInDays($paymentDate);

                    $lastbalanceArrearsInt = round(
                        (float) $lastAmortizationItem['balance_payment']
                        * abs((int) $daysDifference)
                        * (float) $contractDefIntRate
                        / 100,
                        2
                    );

                    if($paymentAmount >= $lastbalanceArrearsInt){
                        $paymentsData[$index]['overdue_interest'] = $lastbalanceArrearsInt;
                        $remainingAmount = $paymentAmount - $lastbalanceArrearsInt;
                        if($remainingAmount > 0){
                            $paymentsData[$index]['overdue_rent'] = $remainingAmount;
                            $amortizationData[$lastAmortizationIndex]['balance_payment'] -= $remainingAmount;
                        }
                    } else {
                        $paymentsData[$index]['overdue_interest'] = $paymentAmount;
                    }
                    // Update the original paymentsData array

                    $lastPaid = $paymentDate;
                }
            }

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
