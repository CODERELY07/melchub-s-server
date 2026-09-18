<?php

namespace App\Http\Controllers;

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
}
