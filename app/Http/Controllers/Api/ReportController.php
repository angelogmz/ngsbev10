<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentBreakdown;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;


class ReportController extends Controller
{
    /**
     * Get daily collection report
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDailyCollectionReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'contract_no' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }

        try {
            $date = $request->date;
            $contractNo = $request->contract_no;

            // Query payments for the specific date
            $query = Payment::whereDate('created_at', $date);

            if ($contractNo) {
                $query->where('contract_no', $contractNo);
            }

            $payments = $query->orderBy('created_at', 'desc')->get();

            // Format the report data
            $reportData = [];
            $totalAmount = 0;
            $userAmounts = [];

            foreach ($payments as $payment) {
                // Extract username from receipt_id
                $parts = explode('-', $payment->receipt_id);
                $username = $parts[1] ?? 'unknown';

                $amount = (float)$payment->payment_amount;

                $reportData[] = [
                    'receipt_id' => $payment->receipt_id,
                    'contract_no' => $payment->contract_no,
                    'payment_amount' => number_format($amount, 2, '.', ''),
                    'payment_date' => $payment->payment_date,
                    'entered_by' => $username,
                    'entered_at' => $payment->created_at,
                    'time' => substr($payment->created_at, 11, 8) // Extract HH:MM:SS from datetime
                ];

                $totalAmount += $amount;

                // Track user totals
                if (!isset($userAmounts[$username])) {
                    $userAmounts[$username] = 0;
                }
                $userAmounts[$username] += $amount;
            }

            // Build user summary
            $userSummary = [];
            foreach ($userAmounts as $user => $total) {
                $count = count(array_filter($reportData, function($item) use ($user) {
                    return $item['entered_by'] == $user;
                }));
                $userSummary[] = [
                    'user' => $user,
                    'count' => $count,
                    'total_amount' => number_format($total, 2, '.', '')
                ];
            }

            return response()->json([
                'success' => true,
                'report_date' => $date,
                'summary' => [
                    'total_transactions' => count($reportData),
                    'total_amount' => number_format($totalAmount, 2, '.', ''),
                    'total_amount_raw' => $totalAmount
                ],
                'user_summary' => $userSummary,
                'transactions' => $reportData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate daily collection report: ' . $e->getMessage()
            ], 500);
        }
    }

        /**
     * Get detailed collection report by date range
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDateRangeCollectionReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'contract_no' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }

        try {
            $startDate = $request->start_date;
            $endDate = $request->end_date;
            $contractNo = $request->contract_no;

            // Query payments within date range (based on created_at)
            $query = Payment::whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate]);

            if ($contractNo) {
                $query->where('contract_no', $contractNo);
            }

            $payments = $query->orderBy('created_at', 'desc')->get();

            $reportData = [];
            $totalAmount = 0;
            $summaryTotals = [
                'overdue_interest' => 0,
                'overdue_cur_interest' => 0,
                'overdue_cur_principal' => 0,
                'current_interest' => 0,
                'current_rent' => 0,
                'future_rent' => 0,
                'future_interest' => 0,
                'future_principal' => 0,
                'excess' => 0
            ];

            foreach ($payments as $payment) {
                // Get breakdown from payment_breakdowns table
                $breakdown = PaymentBreakdown::where('pymnt_id', $payment->pymnt_id)->first();

                if ($breakdown) {
                    $overdueInterest = (float)($breakdown->overdue_interest ?? 0);
                    $overdueCurInterest = (float)($breakdown->overdue_cur_interest ?? 0);
                    $overdueCurPrincipal = (float)($breakdown->overdue_cur_principal ?? 0);
                    $currentInterest = (float)($breakdown->current_interest ?? 0);
                    $currentRent = (float)($breakdown->current_rent ?? 0);
                    $futureRent = (float)($breakdown->future_rent ?? 0);
                    $futureInterest = (float)($breakdown->future_interest ?? 0);
                    $futurePrincipal = (float)($breakdown->future_principal ?? 0);
                    $excess = (float)($breakdown->excess ?? 0);
                    // Add to summary totals
                    $summaryTotals['overdue_interest'] += $overdueInterest;
                    $summaryTotals['overdue_cur_interest'] += $overdueCurInterest;
                    $summaryTotals['overdue_cur_principal'] += $overdueCurPrincipal;
                    $summaryTotals['current_interest'] += $currentInterest;
                    $summaryTotals['current_rent'] += $currentRent;
                    $summaryTotals['future_rent'] += $futureRent;
                    $summaryTotals['future_interest'] += $futureInterest;
                    $summaryTotals['future_principal'] += $futurePrincipal;
                    $summaryTotals['excess'] += $excess;
                }

                // Extract username from receipt_id
                $username = $this->extractUsernameFromReceipt($payment->receipt_id);

                $reportData[] = [
                    'pymnt_id' => $payment->pymnt_id,
                    'receipt_id' => $payment->receipt_id,
                    'contract_no' => $payment->contract_no,
                    'payment_amount' => number_format((float)$payment->payment_amount, 2, '.', ''),
                    'payment_date' => $payment->payment_date,
                    'created_at' => $payment->created_at,
                    'date' => date('Y-m-d', strtotime($payment->created_at)),
                    'time' => date('H:i:s', strtotime($payment->created_at)),
                    'entered_by' => $username,
                    'breakdown' => $breakdown ? [
                        'overdue_interest' => number_format($overdueInterest, 2, '.', ''),
                        'overdue_cur_interest' => number_format($overdueCurInterest, 2, '.', ''),
                        'overdue_cur_principal' => number_format($overdueCurPrincipal, 2, '.', ''),
                        'current_interest' => number_format($currentInterest, 2, '.', ''),
                        'current_rent' => number_format($currentRent, 2, '.', ''),
                        'future_rent' => number_format($futureRent, 2, '.', ''),
                        'future_interest' => number_format($futureInterest, 2, '.', ''),
                        'future_principal' => number_format($futurePrincipal, 2, '.', ''),
                        'excess' => number_format($excess, 2, '.', '')
                    ] : null
                ];

                $totalAmount += (float)$payment->payment_amount;
            }

            // Group by user
            $userSummary = [];
            foreach ($reportData as $item) {
                $user = $item['entered_by'];
                if (!isset($userSummary[$user])) {
                    $userSummary[$user] = [
                        'user' => $user,
                        'count' => 0,
                        'total_amount' => 0
                    ];
                }
                $userSummary[$user]['count']++;
                $userSummary[$user]['total_amount'] += (float)$item['payment_amount'];
            }

            return response()->json([
                'success' => true,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_transactions' => count($reportData),
                    'total_amount' => number_format($totalAmount, 2, '.', ''),
                    'total_amount_raw' => $totalAmount,
                    'breakdown_totals' => [
                        'overdue_interest' => number_format($summaryTotals['overdue_interest'], 2, '.', ''),
                        'overdue_cur_interest' => number_format($summaryTotals['overdue_cur_interest'], 2, '.', ''),
                        'overdue_cur_principal' => number_format($summaryTotals['overdue_cur_principal'], 2, '.', ''),
                        'current_interest' => number_format($summaryTotals['current_interest'], 2, '.', ''),
                        'current_rent' => number_format($summaryTotals['current_rent'], 2, '.', ''),
                        'future_rent' => number_format($summaryTotals['future_rent'], 2, '.', ''),
                        'future_interest' => number_format($summaryTotals['future_interest'], 2, '.', ''),
                        'future_principal' => number_format($summaryTotals['future_principal'], 2, '.', ''),
                        'excess' => number_format($summaryTotals['excess'], 2, '.', '')
                    ]
                ],
                'user_summary' => array_values($userSummary),
                'transactions' => $reportData,
                'generated_at' => date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate date range report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract username from receipt_id
     */
    private function extractUsernameFromReceipt($receiptId)
    {
        if (preg_match('/RCPT_\d{8}-([^-]+)-/', $receiptId, $matches)) {
            return $matches[1];
        }

        $parts = explode('-', $receiptId);
        if (count($parts) >= 2) {
            return $parts[1];
        }

        return 'unknown';
    }
}
