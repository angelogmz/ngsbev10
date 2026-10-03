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
                'message' => 'PA service - No payment breakdowns found for this contract.'
            ], 404);
        }
    }


    public function allocatePayments($contract_no){
        try {
            $contractDetails = $this->getContractDetails($contract_no);
            $data = $contractDetails->getData();
            $contractDefIntRate = $data->def_int_rate;
            $contrExecutionDate = $data->execution_date;

            PaymentBreakdown::where('contract_no', $contract_no)->delete();
            MasterAmortization::where('contract_no', $contract_no)->delete();

            $this->paymentBreakdownService->refreshPaymentBreakdown($contract_no);
            $this->amortizationService->getOrGenerateAmortizationSchedule($contract_no);

            $amortizationTable = MasterAmortization::where('contract_no', $contract_no)
                ->orderBy('due_date')
                ->get();

            $amortizationData = $amortizationTable->map(function ($item) {
                return [
                    'id' => $item->id,
                    'due_date' => $item->due_date,
                    'original_balance_payment' => (float) $item->payment,
                    'balance_payment' => (float) $item->balance_payment,
                    'current_interest' => (float) $item->balance_interest,
                    'current_rent' => (float) $item->balance_principal,
                    'overdue_int' => (float) $item->overdue_int,
                    'completed' => 0,
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
                    'overdue_interest' => (float) ($payment->overdue_interest ?? 0),
                    'overdue_rent' => (float) ($payment->overdue_rent ?? 0),
                    'current_interest' => (float) ($payment->current_interest ?? 0),
                    'current_rent' => (float) ($payment->current_rent ?? 0),
                    'future_rent' => (float) ($payment->future_rent ?? 0),
                    'future_interest' => (float) ($payment->future_interest ?? 0),
                    'future_principal' => (float) ($payment->future_principal ?? 0),
                    'excess' => (float) ($payment->excess ?? 0),
                    'allocated' => (float) ($payment->allocated ?? 0),
                ];
            })->toArray();

            // Pointer to the first incomplete amortization row (only moves forward)
            $openIndex = 0;
            $rowCount  = count($amortizationData);


            foreach ($paymentsData as $pIndex => $payment) {
                // Initialize payment allocation for this payment
                $paymentsData[$pIndex]['overdue_interest'] = 0;
                $paymentsData[$pIndex]['overdue_rent'] = 0;
                $paymentsData[$pIndex]['current_interest'] = 0;
                $paymentsData[$pIndex]['current_rent'] = 0;
                $paymentsData[$pIndex]['future_rent'] = 0;
                $paymentsData[$pIndex]['future_interest'] = 0;
                $paymentsData[$pIndex]['future_principal'] = 0;
                $paymentsData[$pIndex]['excess'] = 0;

                $remainingPayment = round($payment['payment_amount'], 2);
                $paymentDate = $payment['payment_date'];
                $payDay = substr((string) $paymentDate, 0, 10);
                $currentUsed = false;   // first on-time row => current_*, later on-time rows => future_rent

                // keep allocating, row after row, until the payment is used up
                while ($remainingPayment > 0) {

                    // 1) first incomplete row
                    while ($openIndex < $rowCount && $amortizationData[$openIndex]['completed'] == 1) {
                        $openIndex++;
                    }

                    // no rows left => whatever remains is excess
                    if ($openIndex >= $rowCount) {
                        $paymentsData[$pIndex]['excess'] = $remainingPayment;
                        $remainingPayment = 0;
                        break;
                    }

                    $dueDay    = substr((string) $amortizationData[$openIndex]['due_date'], 0, 10);
                    $isOverdue = $payDay > $dueDay;

                    // 2) OVERDUE: calculate arrears for THIS row, then pay it first
                    if ($isOverdue) {
                        $fromDay = $arrearsFrom[$openIndex] ?? $dueDay;

                        // days never go past the NEXT row's due date
                        $nextDueDay = isset($amortizationData[$openIndex + 1])
                            ? substr((string) $amortizationData[$openIndex + 1]['due_date'], 0, 10)
                            : null;
                        $toDay = ($nextDueDay !== null && $nextDueDay < $payDay) ? $nextDueDay : $payDay;

                        $arrearsDays = 0;
                        if ($toDay > $fromDay) {
                            $arrearsDays = (int) round((strtotime($toDay) - strtotime($fromDay)) / 86400);
                        }

                        $unpaidBalance = $amortizationData[$openIndex]['balance_payment'];
                        $dailyRate     = ((float) $contractDefIntRate / 100);
                        $arrears       = round($unpaidBalance * $dailyRate * $arrearsDays, 2);

                        $amortizationData[$openIndex]['overdue_int'] += $arrears;
                        $arrearsFrom[$openIndex] = $toDay;

                        // overdue interest first
                        $overdueDue = round($amortizationData[$openIndex]['overdue_int'], 2);
                        $payOverdue = min($remainingPayment, $overdueDue);
                        $remainingPayment = round($remainingPayment - $payOverdue, 2);

                        $amortizationData[$openIndex]['overdue_int'] = round($overdueDue - $payOverdue, 2);
                        $paymentsData[$pIndex]['overdue_interest'] += $payOverdue;
                    }

                    // 3) row balance (interest first, then principal/rent) from what is left
                    $balanceDue = round($amortizationData[$openIndex]['balance_payment'], 2);
                    $interestDue = round($amortizationData[$openIndex]['current_interest'], 2);
                    $rentDue     = round($amortizationData[$openIndex]['current_rent'], 2);

                    $payBalance = min($remainingPayment, $balanceDue);
                    $remainingPayment = round($remainingPayment - $payBalance, 2);

                    $payInterest = min($payBalance, $interestDue);
                    $payRent     = min(round($payBalance - $payInterest, 2), $rentDue);

                    // reduce the row
                    $amortizationData[$openIndex]['current_interest'] = round($interestDue - $payInterest, 2);
                    $amortizationData[$openIndex]['current_rent']     = round($rentDue - $payRent, 2);
                    $amortizationData[$openIndex]['balance_payment']  =
                        round($amortizationData[$openIndex]['current_interest'] + $amortizationData[$openIndex]['current_rent'], 2);

                    // completed when balance and overdue interest are both cleared
                    $amortizationData[$openIndex]['completed'] =
                        ($amortizationData[$openIndex]['balance_payment'] <= 0
                         && $amortizationData[$openIndex]['overdue_int'] <= 0) ? 1 : 0;

                    // 4) record on the payment (+= because several rows can be paid by one payment)
                    if ($isOverdue || !$currentUsed) {
                        $paymentsData[$pIndex]['current_interest'] = round($paymentsData[$pIndex]['current_interest'] + $payInterest, 2);
                        $paymentsData[$pIndex]['current_rent']     = round($paymentsData[$pIndex]['current_rent'] + $payRent, 2);
                        if (!$isOverdue) {
                            $currentUsed = true;   // the first on-time row is "current"
                        }
                    } else {
                        // later on-time rows are paid in advance => future rent
                        $paymentsData[$pIndex]['future_rent'] =
                            round($paymentsData[$pIndex]['future_rent'] + $payInterest + $payRent, 2);
                    }
                }

                $paymentsData[$pIndex]['allocated'] = round($payment['payment_amount'] - $paymentsData[$pIndex]['excess'], 2);
                $lastPaidDate = $paymentDate;
            }

            // Update master_amortization table
            $caseBalance = [];
            $caseOverDueInt = [];
            $caseCompleted = [];
            $dueDates = [];
            $caseCurrentAmInterest = [];
            $caseCurrentAmPrincipal = [];

            foreach ($amortizationData as $row) {
                $dueDate = $row['due_date'];
                $caseBalance[] = "WHEN due_date = '{$dueDate}' THEN {$row['balance_payment']}";
                $caseCurrentAmInterest[] = "WHEN due_date = '{$dueDate}' THEN {$row['current_interest']}";
                $caseCurrentAmPrincipal[] = "WHEN due_date = '{$dueDate}' THEN {$row['current_rent']}";
                $caseOverDueInt[] = "WHEN due_date = '{$dueDate}' THEN {$row['overdue_int']}";
                $caseCompleted[] = "WHEN due_date = '{$dueDate}' THEN {$row['completed']}";
                $dueDates[] = "'{$dueDate}'";
            }

            if (!empty($caseBalance)) {
                DB::update("
                    UPDATE master_amortization
                    SET
                        balance_payment = CASE " . implode(' ', $caseBalance) . " END,
                        balance_interest = CASE " . implode(' ', $caseCurrentAmInterest) . " END,
                        balance_principal = CASE " . implode(' ', $caseCurrentAmPrincipal) . " END,
                        overdue_int = CASE " . implode(' ', $caseOverDueInt) . " END,
                        completed = CASE " . implode(' ', $caseCompleted) . " END
                    WHERE
                        contract_no = ? AND
                        due_date IN (" . implode(',', $dueDates) . ")
                ", [$contract_no]);
            }

            // Update payment_breakdowns table
            $caseCurrentInterest = [];
            $caseCurrentRent = [];
            $caseOverDueInterest = [];
            $caseOverDueRent = [];
            $caseOverDueCurInterest = [];
            $caseOverDueCurPrincipal = [];
            $caseFutureRent = [];
            $caseFutureInterest = [];
            $caseFuturePrincipal = [];
            $caseExcess = [];
            $ids = [];

            foreach ($paymentsData as $data) {
                $currentInterest = $data['current_interest'] ?? 0;
                $currentRent = $data['current_rent'] ?? 0;
                $overDueInterest = $data['overdue_interest'] ?? 0;
                $overDueRent = $data['overdue_rent'] ?? 0;
                $overdue_cur_interest = $data['overdue_cur_interest'] ?? 0;
                $overdue_cur_principal = $data['overdue_cur_principal'] ?? 0;
                $futureRent = $data['future_rent'] ?? 0;
                $futureInterest = $data['future_interest'] ?? 0;
                $futurePrincipal = $data['future_principal'] ?? 0;
                $excess = $data['excess'] ?? 0;
                $id = $data['pymnt_id'];

                $caseCurrentInterest[] = "WHEN pymnt_id = '{$id}' THEN {$currentInterest}";
                $caseCurrentRent[] = "WHEN pymnt_id = '{$id}' THEN {$currentRent}";
                $caseOverDueInterest[] = "WHEN pymnt_id = '{$id}' THEN {$overDueInterest}";
                $caseOverDueRent[] = "WHEN pymnt_id = '{$id}' THEN {$overDueRent}";
                $caseOverDueCurInterest[] = "WHEN pymnt_id = '{$id}' THEN {$overdue_cur_interest}";
                $caseOverDueCurPrincipal[] = "WHEN pymnt_id = '{$id}' THEN {$overdue_cur_principal}";
                $caseFutureRent[] = "WHEN pymnt_id = '{$id}' THEN {$futureRent}";
                $caseFutureInterest[] = "WHEN pymnt_id = '{$id}' THEN {$futureInterest}";
                $caseFuturePrincipal[] = "WHEN pymnt_id = '{$id}' THEN {$futurePrincipal}";
                $caseExcess[] = "WHEN pymnt_id = '{$id}' THEN {$excess}";
                $ids[] = "'{$id}'";
            }

            if (!empty($caseCurrentInterest)) {
                DB::update("
                    UPDATE payment_breakdowns
                    SET
                        current_interest = CASE " . implode(' ', $caseCurrentInterest) . " END,
                        current_rent = CASE " . implode(' ', $caseCurrentRent) . " END,
                        overdue_interest = CASE " . implode(' ', $caseOverDueInterest) . " END,
                        overdue_rent = CASE " . implode(' ', $caseOverDueRent) . " END,
                        overdue_cur_interest = CASE " . implode(' ', $caseOverDueCurInterest) . " END,
                        overdue_cur_principal = CASE " . implode(' ', $caseOverDueCurPrincipal) . " END,
                        future_rent = CASE " . implode(' ', $caseFutureRent) . " END,
                        future_interest = CASE " . implode(' ', $caseFutureInterest) . " END,
                        future_principal = CASE " . implode(' ', $caseFuturePrincipal) . " END,
                        excess = CASE " . implode(' ', $caseExcess) . " END
                    WHERE pymnt_id IN (" . implode(',', $ids) . ")
                ");
            }

            return response()->json([
                'success' => true,
                'message' => 'Payments allocated successfully for contract ' . $contract_no
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to allocate payments for contract ' . $contract_no . ': ' . $e->getMessage()
            ], 500);
        }
    }

}
