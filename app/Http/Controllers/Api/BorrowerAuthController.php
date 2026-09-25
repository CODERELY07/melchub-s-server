<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
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

        $borrower = Borrower::where('username', $credentials['username'])->first();

        if (! $borrower || ! $borrower->password || ! Hash::check($credentials['password'], $borrower->password)) {
            throw ValidationException::withMessages([
                'username' => ['These credentials do not match our records.'],
            ]);
        }

        // Same reasoning as the staff login (Api\AuthController::login()): the
        // expiry has to be enforced server-side via the token itself.
        $expiresAt = $request->boolean('remember') ? now()->addYear() : now()->addDay();
        $token = $borrower->createToken('borrower_token', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token' => $token,
            'borrower' => $borrower,
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
        $borrower = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:255', Rule::unique('borrowers', 'username')->ignore($borrower->id)],
            'phone' => 'sometimes|nullable|string|max:30',
            'location' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
        ]);

        $borrower->update($validated);

        return response()->json($borrower->fresh());
    }

    public function changePassword(Request $request)
    {
        $borrower = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if (! $borrower->password || ! Hash::check($validated['current_password'], $borrower->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $borrower->update(['password' => $validated['password']]);

        return response()->json(['message' => 'Password updated']);
    }

    /**
     * Records acceptance of the terms & conditions, with the borrower's
     * typed full name standing as their e-signature. One-time — the portal
     * only shows the prompt while terms_accepted_at is still null. This now
     * happens at the account level (once per borrower), not per loan, since
     * a borrower may not have a loan yet when they first accept.
     */
    public function acceptTerms(Request $request)
    {
        $borrower = $request->user();
        $alreadyAccepted = $borrower->terms_accepted_at !== null;

        $validated = $request->validate([
            'signature_name' => 'required|string|max:255',
        ]);

        // Deliberately not in $fillable (borrowers shouldn't be able to set
        // these via updateProfile), so bypass the guard for this one write.
        $borrower->forceFill([
            'terms_accepted_at' => now(),
            'terms_signature_name' => $validated['signature_name'],
        ])->save();

        // Only the very first acceptance is a "welcome" moment — guards
        // against sending it again if this endpoint is ever hit twice (the
        // frontend only shows the prompt while terms_accepted_at is null,
        // but the API itself doesn't otherwise stop a second call).
        if (! $alreadyAccepted && $borrower->phone) {
            $this->sendWelcomeSms($borrower);
        }

        return response()->json($borrower->fresh());
    }

    /**
     * Best-effort — a failed SMS must never undo or block the terms
     * acceptance that already succeeded above. Deliberately generic (no
     * loan amount/dates): terms are accepted at the account level, before a
     * loan necessarily exists yet.
     */
    private function sendWelcomeSms(Borrower $borrower): void
    {
        $message = "Hi {$borrower->name}, welcome to MELCHUB! Thank you for accepting our terms — "
            .'we\'re glad to have you with us and wish you all the best!'
            .$this->accountLinkLine();

        try {
            $this->sms->send($borrower->phone, $message);
        } catch (Throwable $e) {
            Log::warning("Failed to send welcome SMS for borrower {$borrower->id}: {$e->getMessage()}");
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
