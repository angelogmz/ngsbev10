<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\MasterAmortization;
use Carbon\Carbon;

class AmortizationService
{

    /**
     * Regenerate the amortization schedule for a contract.
     *
     * @param Contract $contract
     * @return void
     */
    public function deleteAmortizationSchedule($contractID)
    {
        // Step 1: Delete all existing rows for the contract
        MasterAmortization::where('contract_no', $contractID)->delete();

    }

    public function refreshAmortizationSchedule($contract)
    {

        // Step 1: Get the new amortization schedule
        $newSchedule = $this->generateAmortizationSchedule($contract);


        // Step 2: Get existing records ordered by their expected sequence
        $existingRecords = MasterAmortization::where('contract_no', $contract->contract_no)
            ->orderBy('id') // Assuming earlier records have lower IDs
            ->get();

        // Step 3: Process each installment
        foreach ($newSchedule as $index => $installment) {
            if (isset($existingRecords[$index])) {
                // Update existing record (matched by position)
                $existingRecords[$index]->update($installment);
            } else {
                // Create new record if position didn't exist before
                MasterAmortization::create($installment);
            }
        }

        // Step 4: Delete any extra records beyond the new schedule count
        if (count($existingRecords) > count($newSchedule)) {
            $idsToDelete = $existingRecords->slice(count($newSchedule))
            ->pluck('id')
            ->toArray();

            MasterAmortization::whereIn('id', $idsToDelete)->delete();
        }

        // Return the updated schedule
        return MasterAmortization::where('contract_no', $contract->contract_no)
        ->orderBy('id')
        ->get();
    }

    /**
     * Get or generate the amortization schedule for a given contract_no.
     *
     * @param string $contractNo
     * @return array|null
     */
    public function getOrGenerateAmortizationSchedule($contractNo)
    {
        // Fetch the contract
        $contract = Contract::where('contract_no', $contractNo)->first();

        if (!$contract) {
            return null; // Contract not found
        }

        // Check if the amortization schedule already exists
        $amortizationSchedule = MasterAmortization::where('contract_no', $contractNo)->get();

        if ($amortizationSchedule->isEmpty()) {
            // Generate the amortization schedule
            $amortizationSchedule = $this->generateAmortizationSchedule($contract);

            // Save the generated schedule to the master_amortization_table
            foreach ($amortizationSchedule as $installment) {
               MasterAmortization::create($installment);
            }

            // Fetch the saved schedule
            $amortizationSchedule = MasterAmortization::where('contract_no', $contractNo)->get();
        }

        return $amortizationSchedule;
    }

    /**
     * Generate the amortization schedule for a contract.
     *
     * @param Contract $contract
     * @return array
     */
    /*private function generateAmortizationSchedule($contract)
    {
        $amortizationTable = [];

        $balance = $contract->loan_amount + $contract->cost;

        if ($contract->compounding == 'no compound' || $contract->compounding == null) {
            $exDate = Carbon::createFromFormat('Y-m-d', $contract->loan_execution_date);
            $originalBalance = $balance; // Store the original balance for interest calculation

            for ($i = 0; $i < $contract->term; $i++) {
                if ($contract->pay_freq == 'monthly') {
                    $interest = ($originalBalance * $contract->apr / 12) / 100; // Interest based on original balance
                    $principal = $contract->installments - $interest;
                    $date = $exDate->addMonth();
                    $payment = $interest + $principal;
                }
                if ($contract->pay_freq == 'daily') {
                    $interest = ($originalBalance * $contract->apr / 365) / 100; // Interest based on original balance
                    $principal = $contract->installments - $interest;
                    $date = $exDate->addDay();
                    $payment = $interest + $principal;
                }
                if ($contract->pay_freq == 'weekly') {
                    $interest = ($originalBalance * $contract->apr / 52) / 100; // Interest based on original balance
                    $principal = $contract->installments - $interest;
                    $date = $exDate->addWeek();
                    $payment = $interest + $principal;
                }

                $balance -= $principal; // Reduce the balance by the principal portion

                $row = [
                    'contract_no' => $contract->contract_no,
                    'due_date' => $date->format('Y-m-d'), // Format the date as needed
                    'payment' => number_format((float)$payment, 2, '.', ''),
                    'interest' => number_format((float)$interest, 2, '.', ''),
                    'principal' => number_format((float)$principal, 2, '.', ''),
                    'balance' => number_format((float)$balance, 2, '.', ''),
                    'balance_payment'=> number_format((float)$payment, 2, '.', ''),
                    'balance_interest'=> number_format((float)$interest, 2, '.', ''),
                    'balance_principal'=> number_format((float)$principal, 2, '.', ''),
                    'excess'=> number_format((float)0, 2, '.', ''),
                    'completed'=> 0
                ];

                $amortizationTable[] = $row;
            }
        }
        else{
            $exDate = Carbon::createFromFormat('Y-m-d', $contract->loan_execution_date);
            for ($i = 0; $i < $contract->term; $i++) {

                if($contract->pay_freq == 'monthly'){
                    $interest = ($balance * $contract->apr / 12)/100;
                    $principal = $contract->installments - $interest;
                    $date = $exDate->addMonth();
                    $payment = $interest + $principal;
                }
                if($contract->pay_freq == 'daily'){
                    $interest = ($balance * $contract->apr / 365)/100;
                    $principal = $contract->installments - $interest;
                    $date = $exDate->addDay();
                    $payment = $interest + $principal;
                }
                if($contract->pay_freq == 'weekly'){
                    $interest = ($balance * $contract->apr / 52)/100;
                    $principal = $contract->installments - $interest;
                    $date = $exDate->addWeek();
                    $payment = $interest + $principal;
                }

                $balance = $balance - $principal;

                $row = [
                    'contract_no' => $contract->contract_no,
                    'due_date' => $date->format('Y-m-d'), // Format the date as needed
                    'payment' => number_format((float)$payment, 2, '.', ''),
                    'interest' => number_format((float)$interest, 2, '.', ''),
                    'principal' => number_format((float)$principal, 2, '.', ''),
                    'balance' => number_format((float)$balance, 2, '.', ''),
                    'balance_payment'=> number_format((float)$payment, 2, '.', ''),
                    'balance_interest'=> number_format((float)$interest, 2, '.', ''),
                    'balance_principal'=> number_format((float)$principal, 2, '.', ''),
                    'excess'=> number_format((float)0, 2, '.', ''),
                    'completed'=> 0
                ];

                $amortizationTable[] = $row;

            }

            //

        }

        //print_r($amortizationTable);

        return $amortizationTable;

    }*/

    /**
     * Generate the amortization schedule for a contract.
     *
     * @param Contract $contract
     * @return array
    */
    private function generateAmortizationSchedule($contract)
    {
        $amortizationTable = [];

        $balance = $contract->loan_amount + $contract->cost;

        if ($contract->compounding == 'no compound' || $contract->compounding == null) {
            $exDate = Carbon::createFromFormat('Y-m-d', $contract->loan_execution_date);
            $originalBalance = $balance; // Store the original balance for interest calculation
            $originalDay = $exDate->day; // Store the original day from loan execution date (e.g., 31)

            for ($i = 0; $i < $contract->term; $i++) {
                if ($contract->pay_freq == 'monthly') {
                    $interest = ($originalBalance * $contract->apr / 12) / 100; // Interest based on original balance
                    $principal = $contract->installments - $interest;
                    $date = $this->getNextMonthlyDate($exDate, $originalDay);
                    $payment = $interest + $principal;
                    $exDate = $date; // Update the date for next iteration
                }
                if ($contract->pay_freq == 'daily') {
                    $interest = ($originalBalance * $contract->apr / 365) / 100; // Interest based on original balance
                    $principal = $contract->installments - $interest;
                    $date = $exDate->copy()->addDay();
                    $payment = $interest + $principal;
                    $exDate = $date; // Update the date for next iteration
                }
                if ($contract->pay_freq == 'weekly') {
                    $interest = ($originalBalance * $contract->apr / 52) / 100; // Interest based on original balance
                    $principal = $contract->installments - $interest;
                    $date = $exDate->copy()->addWeek();
                    $payment = $interest + $principal;
                    $exDate = $date; // Update the date for next iteration
                }

                $balance -= $principal; // Reduce the balance by the principal portion

                $row = [
                    'contract_no' => $contract->contract_no,
                    'due_date' => $date->format('Y-m-d'), // Format the date as needed
                    'payment' => number_format((float)$payment, 2, '.', ''),
                    'interest' => number_format((float)$interest, 2, '.', ''),
                    'principal' => number_format((float)$principal, 2, '.', ''),
                    'balance' => number_format((float)$balance, 2, '.', ''),
                    'balance_payment'=> number_format((float)$payment, 2, '.', ''),
                    'balance_interest'=> number_format((float)$interest, 2, '.', ''),
                    'balance_principal'=> number_format((float)$principal, 2, '.', ''),
                    'excess'=> number_format((float)0, 2, '.', ''),
                    'completed'=> 0
                ];

                $amortizationTable[] = $row;
            }
        }
        else{
            $exDate = Carbon::createFromFormat('Y-m-d', $contract->loan_execution_date);
            $originalDay = $exDate->day; // Store the original day from loan execution date

            for ($i = 0; $i < $contract->term; $i++) {

                if($contract->pay_freq == 'monthly'){
                    $interest = ($balance * $contract->apr / 12)/100;
                    $principal = $contract->installments - $interest;
                    $date = $this->getNextMonthlyDate($exDate, $originalDay);
                    $payment = $interest + $principal;
                    $exDate = $date; // Update the date for next iteration
                }
                if($contract->pay_freq == 'daily'){
                    $interest = ($balance * $contract->apr / 365)/100;
                    $principal = $contract->installments - $interest;
                    $date = $exDate->copy()->addDay();
                    $payment = $interest + $principal;
                    $exDate = $date; // Update the date for next iteration
                }
                if($contract->pay_freq == 'weekly'){
                    $interest = ($balance * $contract->apr / 52)/100;
                    $principal = $contract->installments - $interest;
                    $date = $exDate->copy()->addWeek();
                    $payment = $interest + $principal;
                    $exDate = $date; // Update the date for next iteration
                }

                $balance = $balance - $principal;

                $row = [
                    'contract_no' => $contract->contract_no,
                    'due_date' => $date->format('Y-m-d'), // Format the date as needed
                    'payment' => number_format((float)$payment, 2, '.', ''),
                    'interest' => number_format((float)$interest, 2, '.', ''),
                    'principal' => number_format((float)$principal, 2, '.', ''),
                    'balance' => number_format((float)$balance, 2, '.', ''),
                    'balance_payment'=> number_format((float)$payment, 2, '.', ''),
                    'balance_interest'=> number_format((float)$interest, 2, '.', ''),
                    'balance_principal'=> number_format((float)$principal, 2, '.', ''),
                    'excess'=> number_format((float)0, 2, '.', ''),
                    'completed'=> 0
                ];

                $amortizationTable[] = $row;
            }
        }

        return $amortizationTable;
    }

    /**
     * Get the next monthly due date while preserving the original day pattern
     *
     * Examples with original day = 31:
     * - Jan 31, 2024 -> Feb 29, 2024 (adjusted to Feb 29)
     * - Feb 29, 2024 -> Mar 31, 2024 (returns to original 31st)
     * - Mar 31, 2024 -> Apr 30, 2024 (adjusted to Apr 30)
     * - Apr 30, 2024 -> May 31, 2024 (returns to original 31st)
     * - May 31, 2024 -> Jun 30, 2024 (adjusted to Jun 30)
     * - Jun 30, 2024 -> Jul 31, 2024 (returns to original 31st)
     *
     * Examples with original day = 30:
     * - Jan 30, 2024 -> Feb 29, 2024 (adjusted to Feb 29)
     * - Feb 29, 2024 -> Mar 30, 2024 (returns to original 30th)
     * - Mar 30, 2024 -> Apr 30, 2024 (stays at 30th)
     * - Apr 30, 2024 -> May 30, 2024 (stays at 30th)
     *
     * Examples with original day = 15:
     * - Jan 15, 2024 -> Feb 15, 2024 (stays at 15th)
     * - Feb 15, 2024 -> Mar 15, 2024 (stays at 15th)
     * - Mar 15, 2024 -> Apr 15, 2024 (stays at 15th)
     *
     * @param Carbon $currentDate
     * @param int $originalDay The original day from the first payment (e.g., 31, 30, 15)
     * @return Carbon
     */
    private function getNextMonthlyDate($currentDate, $originalDay)
    {
        $year = $currentDate->year;
        $month = $currentDate->month;

        // Calculate next month and year
        if ($month == 12) {
            $nextMonth = 1;
            $nextYear = $year + 1;
        } else {
            $nextMonth = $month + 1;
            $nextYear = $year;
        }

        // Get the number of days in the next month
        $daysInNextMonth = date('t', strtotime("{$nextYear}-{$nextMonth}-01"));

        // Use the original day if it exists in the next month, otherwise use the last day of the month
        if ($originalDay > $daysInNextMonth) {
            $dueDay = $daysInNextMonth;
        } else {
            $dueDay = $originalDay;
        }

        // Create the date string and return Carbon instance
        $dateString = "{$nextYear}-{$nextMonth}-{$dueDay}";
        return Carbon::createFromFormat('Y-m-d', $dateString);
    }

}
