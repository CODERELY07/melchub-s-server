<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Readable by any authenticated account (staff or borrower) — the
     * borrower portal needs this to show where to send GCash payments.
     */
    public function paymentInfo()
    {
        return response()->json([
            'gcash_name' => Setting::get('gcash_name', ''),
            'gcash_number' => Setting::get('gcash_number', ''),
        ]);
    }

    /**
     * Admin-only — see routes/api.php for the middleware that enforces that.
     */
    public function updatePaymentInfo(Request $request)
    {
        $validated = $request->validate([
            'gcash_name' => 'required|string|max:255',
            'gcash_number' => 'required|string|max:50',
        ]);

        Setting::set('gcash_name', $validated['gcash_name']);
        Setting::set('gcash_number', $validated['gcash_number']);

        return response()->json([
            'gcash_name' => $validated['gcash_name'],
            'gcash_number' => $validated['gcash_number'],
        ]);
    }

    /**
     * Admin-only, and deliberately a separate endpoint from paymentInfo()
     * above rather than folded into the same settings object — this phone
     * number is where new-payment-proof alerts go (PaymentProofController)
     * and has no reason to be readable by borrower tokens the way the GCash
     * account does.
     */
    public function notifications()
    {
        return response()->json([
            'admin_notify_phone' => Setting::get('admin_notify_phone', ''),
        ]);
    }

    public function updateNotifications(Request $request)
    {
        $validated = $request->validate([
            'admin_notify_phone' => 'nullable|string|max:30',
        ]);

        Setting::set('admin_notify_phone', $validated['admin_notify_phone'] ?? '');

        return response()->json([
            'admin_notify_phone' => $validated['admin_notify_phone'] ?? '',
        ]);
    }

    /**
     * Admin-only: the shared lending pool. Deliberately not part of
     * loanDefaults() below, which any borrower token can read — a borrower
     * only ever sees their own resulting available_credit, never the total
     * pool or what's left of it.
     */
    public function lendingBudget()
    {
        return response()->json([
            'lending_budget' => Setting::get('lending_budget', ''),
            'remaining_budget' => Loan::remainingBudget(),
        ]);
    }

    public function updateLendingBudget(Request $request)
    {
        $validated = $request->validate([
            'lending_budget' => 'nullable|numeric|min:0',
        ]);

        Setting::set('lending_budget', (string) ($validated['lending_budget'] ?? ''));
        Loan::flushBudgetCache();

        return response()->json([
            'lending_budget' => Setting::get('lending_budget', ''),
            'remaining_budget' => Loan::remainingBudget(),
        ]);
    }

    /**
     * Readable by any authenticated account for the same reason as
     * paymentInfo() above — a borrower reading this over the borrower API
     * would just see the same number their own reminder SMS already quotes,
     * nothing sensitive. The default (50) matches what NotificationController
     * falls back to if this is somehow never set.
     */
    public function loanDefaults()
    {
        return response()->json([
            'late_fee_amount' => Setting::get('late_fee_amount', '50'),
        ]);
    }

    /**
     * Admin-only. A single fee for every loan regardless of repayment_plan —
     * see docs/loans.md Part 7 for why this isn't per-plan.
     */
    public function updateLoanDefaults(Request $request)
    {
        $validated = $request->validate([
            'late_fee_amount' => 'required|numeric|min:0',
        ]);

        Setting::set('late_fee_amount', (string) $validated['late_fee_amount']);

        return response()->json([
            'late_fee_amount' => (string) $validated['late_fee_amount'],
        ]);
    }
}
