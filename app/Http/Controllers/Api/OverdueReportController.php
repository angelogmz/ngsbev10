<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OverdueReportController extends Controller
{
    /**
     * Export overdue contracts to CSV
     */
    public function exportOverdueContracts(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $overdueContracts = $this->getOverdueContracts();

        if (empty($overdueContracts)) {
            return response()->json(['message' => 'No overdue contracts found'], 404);
        }

        return $this->streamCSV($overdueContracts);
    }

    private function getOverdueContracts()
    {
        $today = date('Y-m-d');

        // Get ALL distinct contract numbers that have overdue rows
        $overdueContracts = DB::select("
            SELECT DISTINCT ma.contract_no
            FROM master_amortization ma
            WHERE ma.due_date < ?
              AND ma.completed = 0
              AND ma.balance_payment > 0
            ORDER BY ma.contract_no
        ", [$today]);

        if (empty($overdueContracts)) {
            return [];
        }

        $contractNumbers = array_column($overdueContracts, 'contract_no');

        // Process ALL contracts in one go (not just first)
        return $this->processAllContracts($contractNumbers, $today);
    }

    private function processAllContracts($contractNumbers, $today)
    {
        if (empty($contractNumbers)) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($contractNumbers), '?'));

        // Get ALL contracts data in one query
        $contracts = DB::select("
            SELECT
                c.contract_no,
                c.customer_id,
                c.loan_type,
                c.loan_amount,
                c.cost,
                c.apr,
                c.term,
                c.pay_freq,
                c.due_date,
                c.installments,
                c.total_payment,
                c.total_interest,
                c.def_int_rate,
                c.compounding,
                c.loan_execution_date,
                c.loan_end_date,
                c.status,
                cust.name as customer_name,
                cust.contact_no as customer_contact,
                cust.address as customer_address,
                cust.email as customer_email,
                cust.centre as customer_centre
            FROM contracts c
            LEFT JOIN customers cust ON c.customer_id = cust.id
            WHERE c.contract_no IN ({$placeholders})
        ", $contractNumbers);

        // Get ALL overdue amortization data
        $overdueData = DB::select("
            SELECT
                contract_no,
                MIN(due_date) as first_due_date,
                MAX(due_date) as last_due_date,
                SUM(balance_payment) as total_balance,
                COUNT(*) as row_count
            FROM master_amortization
            WHERE contract_no IN ({$placeholders})
              AND due_date < ?
              AND completed = 0
              AND balance_payment > 0
            GROUP BY contract_no
        ", array_merge($contractNumbers, [$today]));

        // Index by contract_no
        $overdueIndexed = [];
        foreach ($overdueData as $data) {
            $overdueIndexed[$data->contract_no] = $data;
        }

        // Get ALL guarantors
        $guarantors = DB::select("
            SELECT
                contract_no,
                MAX(CASE WHEN num = 1 THEN title END) as guarantor_1_title,
                MAX(CASE WHEN num = 1 THEN name END) as guarantor_1_name,
                MAX(CASE WHEN num = 1 THEN contact_no END) as guarantor_1_contact,
                MAX(CASE WHEN num = 1 THEN address END) as guarantor_1_address,
                MAX(CASE WHEN num = 1 THEN email END) as guarantor_1_email,
                MAX(CASE WHEN num = 2 THEN title END) as guarantor_2_title,
                MAX(CASE WHEN num = 2 THEN name END) as guarantor_2_name,
                MAX(CASE WHEN num = 2 THEN contact_no END) as guarantor_2_contact,
                MAX(CASE WHEN num = 2 THEN address END) as guarantor_2_address,
                MAX(CASE WHEN num = 2 THEN email END) as guarantor_2_email
            FROM (
                SELECT
                    g.*,
                    @num := IF(@prev_contract = contract_no, @num + 1, 1) as num,
                    @prev_contract := contract_no
                FROM guarantors g
                CROSS JOIN (SELECT @num := 0, @prev_contract := '') vars
                WHERE contract_no IN ({$placeholders})
                ORDER BY contract_no, id
            ) numbered
            WHERE num <= 2
            GROUP BY contract_no
        ", $contractNumbers);

        // Index guarantors by contract_no
        $guarantorsIndexed = [];
        foreach ($guarantors as $g) {
            $guarantorsIndexed[$g->contract_no] = $g;
        }

        // Build results for ALL contracts
        $results = [];
        foreach ($contracts as $contract) {
            $overdue = $overdueIndexed[$contract->contract_no] ?? null;
            if (!$overdue) {
                continue;
            }

            // Calculate days overdue
            $firstDueDate = $overdue->first_due_date;
            $todayObj = new \DateTime($today);
            $dueDateObj = new \DateTime($firstDueDate);
            $interval = $dueDateObj->diff($todayObj);
            $daysOverdue = $interval->days;

            $guarantor = $guarantorsIndexed[$contract->contract_no] ?? null;

            $results[] = [
                $contract->contract_no,
                $contract->customer_id,
                $contract->customer_name ?? '',
                $contract->customer_contact ?? '',
                $contract->customer_address ?? '',
                $contract->customer_email ?? '',
                $contract->customer_centre ?? '',
                $contract->loan_type,
                $contract->loan_amount,
                $contract->cost ?? '',
                $contract->apr,
                $contract->term,
                $contract->pay_freq ?? '',
                $contract->due_date,
                $contract->installments,
                $contract->total_payment,
                $contract->total_interest,
                $contract->def_int_rate ?? '',
                $contract->compounding ?? '',
                $contract->loan_execution_date,
                $contract->loan_end_date,
                $contract->status,
                $overdue->first_due_date,
                $overdue->last_due_date,
                $daysOverdue,
                number_format($overdue->total_balance, 2),
                $overdue->row_count,

                // Guarantor 1
                $guarantor->guarantor_1_name ?? '',
                $guarantor->guarantor_1_title ?? '',
                $guarantor->guarantor_1_contact ?? '',
                $guarantor->guarantor_1_address ?? '',
                $guarantor->guarantor_1_email ?? '',

                // Guarantor 2
                $guarantor->guarantor_2_name ?? '',
                $guarantor->guarantor_2_title ?? '',
                $guarantor->guarantor_2_contact ?? '',
                $guarantor->guarantor_2_address ?? '',
                $guarantor->guarantor_2_email ?? '',
            ];
        }

        return $results;
    }

    private function streamCSV($data)
    {
        $headers = [
            'Contract No', 'Customer ID', 'Customer Name', 'Customer Contact',
            'Customer Address', 'Customer Email', 'Customer Centre',
            'Loan Type', 'Loan Amount', 'Cost', 'APR', 'Term', 'Pay Freq',
            'Due Date', 'Installments', 'Total Payment', 'Total Interest',
            'Default Int Rate', 'Compounding', 'Loan Execution Date', 'Loan End Date',
            'Status', 'First Overdue Date', 'Last Overdue Date', 'Days Overdue',
            'Total Overdue Balance', 'Overdue Rows Count',
            'Guarantor 1 Name', 'Guarantor 1 Title', 'Guarantor 1 Contact',
            'Guarantor 1 Address', 'Guarantor 1 Email',
            'Guarantor 2 Name', 'Guarantor 2 Title', 'Guarantor 2 Contact',
            'Guarantor 2 Address', 'Guarantor 2 Email'
        ];

        $callback = function() use ($data, $headers) {
            $file = fopen('php://output', 'w');
            fputs($file, "\xEF\xBB\xBF");
            fputcsv($file, $headers);

            foreach ($data as $row) {
                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="overdue_contracts_' . date('Y-m-d') . '.csv"',
        ]);
    }
}
