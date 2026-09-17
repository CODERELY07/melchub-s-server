<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BorrowerAuthController extends Controller
{
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

        $validated = $request->validate([
            'signature_name' => 'required|string|max:255',
        ]);

        // Deliberately not in $fillable (borrowers shouldn't be able to set
        // these via updateProfile), so bypass the guard for this one write.
        $loan->forceFill([
            'terms_accepted_at' => now(),
            'terms_signature_name' => $validated['signature_name'],
        ])->save();

        return response()->json($loan->fresh());
    }
}
