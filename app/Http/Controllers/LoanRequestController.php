<?php

namespace App\Http\Controllers;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanRequest;
use App\Models\RepaymentPlan;
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
     * boundary). `requested_amount` is checked against the borrower's own
     * available_credit (Borrower::availableCredit(), summed across all
     * their loans) for the same reason — the frontend already disables
     * amounts above it, but that's UX only too.
     */
    public function store(Request $request)
    {
        $borrower = $request->user();

        $validated = $request->validate([
            'plan' => ['required', \Illuminate\Validation\Rule::exists('repayment_plans', 'key')->where('is_active', true)],
            'requested_amount' => 'required|numeric|min:1',
            'message' => 'sometimes|nullable|string|max:1000',
            'acknowledged' => 'required|accepted',
        ]);

        if ($borrower->available_credit === null) {
            return response()->json([
                'message' => "Your loan officer hasn't set a borrowing limit for your account yet. Please contact them directly.",
            ], 422);
        }

        if ($validated['requested_amount'] > $borrower->available_credit) {
            return response()->json([
                'message' => 'That amount is more than your available credit of ₱'.number_format((float) $borrower->available_credit, 2).'.',
            ], 422);
        }

        $loanRequest = $borrower->loanRequests()->create([
            'plan' => $validated['plan'],
            'requested_amount' => $validated['requested_amount'],
            'message' => $validated['message'] ?? null,
            'rules_acknowledged_at' => now(),
        ]);

        $this->tryNotifyAdmin($borrower, $loanRequest);

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
        $query = LoanRequest::with(['borrower:id,name,username,phone,credit_limit', 'reviewer:id,name'])->latest();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->get());
    }

    /**
     * Admin: accept a request — this actually creates the loan now, active
     * and recorded right away, rather than just marking the request
     * reviewed and leaving the admin to set it up manually afterward
     * (that's how this used to work; see docs/loans.md Part 7 for why, and
     * the "Borrowers can have multiple loans" section for why it changed).
     *
     * interest_rate/repayment_plan come from the plan the borrower picked;
     * start_date is today and due_date one period out, the same convention
     * every other due date in this app already follows (a single date that
     * keeps pushing forward by the plan's period on a late payment, not a
     * fixed end-of-term date).
     */
    public function accept(Request $request, LoanRequest $loanRequest)
    {
        if ($loanRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been reviewed.'], 422);
        }

        $borrower = $loanRequest->borrower;

        // Re-check now, not just at request time — the borrower's available
        // credit (other loans, the shared budget) may have moved since they
        // asked.
        if ($borrower->available_credit === null || $loanRequest->requested_amount > $borrower->available_credit) {
            return response()->json([
                'message' => $borrower->available_credit === null
                    ? "This borrower no longer has a borrowing limit set."
                    : 'This now exceeds their available credit of ₱'.number_format((float) $borrower->available_credit, 2).'.',
            ], 422);
        }

        $plan = RepaymentPlan::lookup($loanRequest->plan);
        $startDate = today();

        $loan = Loan::create([
            'borrower_id' => $borrower->id,
            'total_loan' => $loanRequest->requested_amount,
            'interest_rate' => $plan?->daily_rate ?? 0,
            'repayment_plan' => $loanRequest->plan,
            'installments_enabled' => true,
            'status' => 'active',
            'start_date' => $startDate,
            'due_date' => $startDate->copy()->addDays($plan?->period_days ?? 7),
            'created_by' => $request->user()->id,
        ]);

        $loanRequest->update([
            'status' => 'accepted',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($borrower->phone) {
            try {
                $this->sms->send(
                    $borrower->phone,
                    "Hi {$borrower->name}, your loan request was approved! Loan {$loan->loan_number} for ₱"
                        .number_format((float) $loan->total_loan, 2)." is now active, due ".$loan->due_date->format('M d, Y').'.'
                );
            } catch (Throwable $e) {
                Log::warning("Failed to send loan-request accept SMS for request {$loanRequest->id}: {$e->getMessage()}");
            }
        }

        return response()->json([
            'request' => $loanRequest->fresh(['borrower', 'reviewer']),
            'loan' => $loan->fresh(),
        ]);
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

        $borrower = $loanRequest->borrower;
        if ($borrower->phone) {
            try {
                $this->sms->send(
                    $borrower->phone,
                    "Hi {$borrower->name}, your loan request wasn't approved: {$validated['note']}"
                );
            } catch (Throwable $e) {
                Log::warning("Failed to send loan-request decline SMS for request {$loanRequest->id}: {$e->getMessage()}");
            }
        }

        return response()->json($loanRequest->fresh(['borrower', 'reviewer']));
    }

    /**
     * Best-effort admin alert, same pattern as
     * PaymentProofController::tryNotifyAdminOfNewProof() — silent no-op if
     * no notification phone is configured.
     */
    private function tryNotifyAdmin(Borrower $borrower, LoanRequest $loanRequest): void
    {
        $adminPhone = Setting::get('admin_notify_phone');
        if (! $adminPhone) {
            return;
        }

        $planLabel = \App\Models\RepaymentPlan::lookup($loanRequest->plan)?->name ?? $loanRequest->plan;

        try {
            $this->sms->send(
                $adminPhone,
                "New loan request from {$borrower->name} (@{$borrower->username}) for ₱"
                    .number_format((float) $loanRequest->requested_amount, 2)." — {$planLabel}."
            );
        } catch (Throwable $e) {
            Log::warning("Failed to send new-loan-request admin alert for request {$loanRequest->id}: {$e->getMessage()}");
        }
    }
}
