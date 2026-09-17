<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanPayment;

class AnalyticsController extends Controller
{
    /**
     * Aggregate figures for the admin dashboard's charts and cards. Computed
     * in PHP rather than raw SQL grouping — the dataset is admin-tool sized,
     * and this avoids MySQL/Postgres/SQLite date-function differences.
     */
    public function index()
    {
        $loans = Loan::all();
        $payments = LoanPayment::all();

        $months = collect();
        for ($i = 11; $i >= 0; $i--) {
            $months->push(today()->subMonths($i)->format('Y-m'));
        }

        $loansByMonth = $loans->groupBy(fn ($loan) => $loan->created_at->format('Y-m'));
        $paymentsByMonth = $payments->groupBy(fn ($payment) => $payment->paid_at->format('Y-m'));

        $monthlyLoans = $months->map(function ($month) use ($loansByMonth) {
            $group = $loansByMonth->get($month, collect());

            return [
                'month' => $month,
                'count' => $group->count(),
                'amount' => round($group->sum(fn ($loan) => (float) $loan->total_loan), 2),
            ];
        })->values();

        $monthlyCollections = $months->map(function ($month) use ($paymentsByMonth) {
            return [
                'month' => $month,
                'amount' => round($paymentsByMonth->get($month, collect())->sum(fn ($p) => (float) $p->amount), 2),
            ];
        })->values();

        $statusBreakdown = collect(['pending', 'active', 'paid', 'overdue', 'defaulted', 'cancelled'])
            ->map(fn ($status) => [
                'status' => $status,
                'count' => $loans->where('status', $status)->count(),
            ])
            ->values();

        return response()->json([
            'totals' => [
                'loan_count' => $loans->count(),
                'total_loaned' => round($loans->sum(fn ($loan) => (float) $loan->total_loan), 2),
                'total_collected' => round($loans->sum(fn ($loan) => (float) $loan->total_paid), 2),
                'total_outstanding' => round($loans->sum(fn ($loan) => $loan->balance), 2),
                'overdue_count' => $loans->filter(fn ($loan) => $loan->is_overdue)->count(),
                'active_count' => $loans->where('status', 'active')->count(),
                'paid_count' => $loans->where('status', 'paid')->count(),
            ],
            'status_breakdown' => $statusBreakdown,
            'monthly_loans' => $monthlyLoans,
            'monthly_collections' => $monthlyCollections,
        ]);
    }
}
