<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanRequest;
use App\Models\Setting;
use App\Services\SmsGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class LoanRequestController extends Controller
{
    public function __construct(private SmsGatewayService $sms)
    {
    }

    /**
     * Borrower: ask for a new/renewed loan. `acknowledged` must be explicitly
     * true — the frontend disables its submit button until the rules
     * checkbox is checked, but that's UX only; this validation is the real
     * gate, same reasoning as every other client-side check in this app
     * (see docs/rbac.md's note on frontend checks never being the security
     * boundary). `requested_amount` is checked against the loan's own
     * available_credit (Loan::availableCredit()) for the same reason — the
     * frontend already disables amounts above it, but that's UX only too.
     */
    public function store(Request $request)
    {
        $loan = $request->user();

        $validated = $request->validate([
            'plan' => 'required|in:3_day,weekly',
            'requested_amount' => 'required|numeric|min:1',
            'message' => 'sometimes|nullable|string|max:1000',
            'acknowledged' => 'required|accepted',
        ]);

        if ($loan->available_credit === null) {
            return response()->json([
                'message' => "Your loan officer hasn't set a borrowing limit for your account yet. Please contact them directly.",
            ], 422);
        }

        if ($validated['requested_amount'] > $loan->available_credit) {
            return response()->json([
                'message' => 'That amount is more than your available credit of ₱'.number_format((float) $loan->available_credit, 2).'.',
            ], 422);
        }

        $loanRequest = $loan->loanRequests()->create([
            'plan' => $validated['plan'],
            'requested_amount' => $validated['requested_amount'],
            'message' => $validated['message'] ?? null,
            'rules_acknowledged_at' => now(),
        ]);

        $this->tryNotifyAdmin($loan, $loanRequest);

        return response()->json($loanRequest, 201);
    }

    /**
     * Borrower: their own requests, most recent first.
     */
    public function mine(Request $request)
    {
        return response()->json(
            $request->user()->loanRequests()->latest()->get()
        );
    }

    /**
     * Admin: every request, optionally filtered by status.
     */
    public function index(Request $request)
    {
        $query = LoanRequest::with(['loan:id,loan_number,name,phone,status,total_loan,credit_limit', 'reviewer:id,name'])->latest();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->get());
    }

    /**
     * Admin: acknowledge the request as handled. Deliberately does NOT touch
     * the loan's own fields — the admin still sets the real principal,
     * interest rate, and dates via the existing Loans page/"Edit loan"
     * modal, exactly as before this feature existed (see docs/loans.md
     * Part 7 for why this was kept this simple).
     */
    public function accept(Request $request, LoanRequest $loanRequest)
    {
        if ($loanRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been reviewed.'], 422);
        }

        $loanRequest->update([
            'status' => 'accepted',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json($loanRequest->fresh(['loan', 'reviewer']));
    }

    /**
     * Admin: decline a request — the note is required and is exactly what
     * gets texted to the borrower explaining why.
     */
    public function decline(Request $request, LoanRequest $loanRequest)
    {
        if ($loanRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been reviewed.'], 422);
        }

        $validated = $request->validate([
            'note' => 'required|string|max:1000',
        ]);

        $loanRequest->update([
            'status' => 'declined',
            'admin_note' => $validated['note'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $loan = $loanRequest->loan;
        if ($loan->phone) {
            try {
                $this->sms->send(
                    $loan->phone,
                    "Hi {$loan->name}, your loan request wasn't approved: {$validated['note']}",
                    $loan->id
                );
            } catch (Throwable $e) {
                Log::warning("Failed to send loan-request decline SMS for request {$loanRequest->id}: {$e->getMessage()}");
            }
        }

        return response()->json($loanRequest->fresh(['loan', 'reviewer']));
    }

    /**
     * Best-effort admin alert, same pattern as
     * PaymentProofController::tryNotifyAdminOfNewProof() — silent no-op if
     * no notification phone is configured.
     */
    private function tryNotifyAdmin(Loan $loan, LoanRequest $loanRequest): void
    {
        $adminPhone = Setting::get('admin_notify_phone');
        if (! $adminPhone) {
            return;
        }

        $planLabel = $loanRequest->plan === '3_day' ? '3-day installment' : 'weekly installment';
        $frontendUrl = rtrim((string) config('services.frontend_url'), '/');
        $link = $frontendUrl ? " Review it: {$frontendUrl}/admin/loan-requests" : '';

        try {
            $this->sms->send(
                $adminPhone,
                "New loan request from {$loan->name} (loan {$loan->loan_number}) for ₱"
                    .number_format((float) $loanRequest->requested_amount, 2)." — {$planLabel} plan.{$link}"
            );
        } catch (Throwable $e) {
            Log::warning("Failed to send new-loan-request admin alert for request {$loanRequest->id}: {$e->getMessage()}");
        }
    }
}
