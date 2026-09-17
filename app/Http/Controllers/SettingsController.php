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
}
