<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\SmsGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BorrowerAuthController extends Controller
{
    public function __construct(private SmsGatewayService $sms)
    {
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'remember' => 'sometimes|boolean',
        ]);

        $loan = Loan::where('username', $credentials['username'])->first();

        if (! $loan || ! $loan->password || ! Hash::check($credentials['password'], $loan->password)) {
            throw ValidationException::withMessages([
                'username' => ['These credentials do not match our records.'],
            ]);
        }

        // Same reasoning as the staff login (Api\AuthController::login()): the
        // expiry has to be enforced server-side via the token itself.
        $expiresAt = $request->boolean('remember') ? now()->addYear() : now()->addDay();
        $token = $loan->createToken('borrower_token', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token' => $token,
            'loan' => $loan,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        $loan = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:255', Rule::unique('loans', 'username')->ignore($loan->id)],
            'phone' => 'sometimes|nullable|string|max:30',
            'location' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
        ]);

        $loan->update($validated);

        return response()->json($loan->fresh());
    }

    public function changePassword(Request $request)
    {
        $loan = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if (! $loan->password || ! Hash::check($validated['current_password'], $loan->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $loan->update(['password' => $validated['password']]);

        return response()->json(['message' => 'Password updated']);
    }

    public function history(Request $request)
    {
        return response()->json($request->user()->history());
    }

    /**
     * Records acceptance of the terms & conditions, with the borrower's
     * typed full name standing as their e-signature. One-time — the portal
     * only shows the prompt while terms_accepted_at is still null.
     */
    public function acceptTerms(Request $request)
    {
        $loan = $request->user();
        $alreadyAccepted = $loan->terms_accepted_at !== null;

        $validated = $request->validate([
            'signature_name' => 'required|string|max:255',
        ]);

        // Deliberately not in $fillable (borrowers shouldn't be able to set
        // these via updateProfile), so bypass the guard for this one write.
        $loan->forceFill([
            'terms_accepted_at' => now(),
            'terms_signature_name' => $validated['signature_name'],
        ])->save();

        // Only the very first acceptance is a "welcome" moment — guards
        // against sending it again if this endpoint is ever hit twice (the
        // frontend only shows the prompt while terms_accepted_at is null,
        // but the API itself doesn't otherwise stop a second call).
        if (! $alreadyAccepted && $loan->phone) {
            $this->sendWelcomeSms($loan);
        }

        return response()->json($loan->fresh());
    }

    /**
     * Best-effort — a failed SMS must never undo or block the terms
     * acceptance that already succeeded above.
     */
    private function sendWelcomeSms(Loan $loan): void
    {
        $message = "Hi {$loan->name}, welcome to MELCHUB! Your loan of ₱".number_format((float) $loan->total_loan, 2)
            ." is now active, starting {$loan->start_date->format('M d, Y')} and due on {$loan->due_date->format('M d, Y')}. "
            .'Thank you for your trust — we\'re glad to have you with us and wish you all the best!'
            .$this->accountLinkLine();

        try {
            $this->sms->send($loan->phone, $message, $loan->id);
        } catch (Throwable $e) {
            Log::warning("Failed to send welcome SMS for loan {$loan->id}: {$e->getMessage()}");
        }
    }

    /**
     * " View your account: https://..." (or "" if FRONTEND_URL is unset) —
     * same helper/text as NotificationController::accountLinkLine(), kept
     * as a small duplicate rather than a shared trait for two controllers,
     * one call site each. Root "/" there already redirects to the right
     * place for whoever opens it (see client/app/page.tsx), and on Android
     * with the PWA installed, the OS may open it in the installed app
     * instead of a browser tab.
     */
    private function accountLinkLine(): string
    {
        $url = rtrim((string) config('services.frontend_url'), '/');

        return $url ? " View your account: {$url}" : '';
    }
}
