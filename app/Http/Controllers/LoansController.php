<?php

namespace App\Http\Controllers;

use App\Models\Borrower;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoansController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Loan::query()->with('borrower')->latest();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas('borrower', function ($b) use ($search) {
                        $b->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created resource in storage. Two shapes:
     *  - `borrower_id` present: a new loan for an EXISTING borrower (the
     *    "New loan" button next to a borrower once they already have one).
     *  - no `borrower_id`: a brand-new borrower, created from the identity
     *    fields; a loan is created alongside it only if loan terms
     *    (total_loan) were actually sent — the "Add Client" page sends
     *    none, so that path creates a borrower with zero loans, to have its
     *    first loan added later the same way as a second one.
     */
    public function store(Request $request)
    {
        if ($request->filled('borrower_id')) {
            $request->validate(['borrower_id' => 'required|integer|exists:borrowers,id']);
            $borrower = Borrower::findOrFail($request->input('borrower_id'));

            $loan = Loan::create($this->validatedLoanFields($request) + [
                'borrower_id' => $borrower->id,
                'created_by' => $request->user()->id,
                'status' => $request->input('status') ?? 'active',
            ]);

            return response()->json($loan->fresh(), 201);
        }

        $identity = $this->validatedIdentity($request);
        if (empty($identity['password'])) {
            unset($identity['password']);
        }

        $borrower = Borrower::create($identity + ['created_by' => $request->user()->id]);

        if (! $request->filled('total_loan')) {
            return response()->json($borrower->fresh(), 201);
        }

        $loan = Loan::create($this->validatedLoanFields($request) + [
            'borrower_id' => $borrower->id,
            'created_by' => $request->user()->id,
            // Newly created clients are active right away, not stuck in the
            // DB-default 'pending'; an explicit status (New loan form) wins.
            'status' => $request->input('status') ?? 'active',
        ]);

        return response()->json($loan->fresh(), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Loan $loan)
    {
        return response()->json($loan);
    }

    /**
     * Update the specified resource in storage. Identity fields (name,
     * username, email, password, phone, location, credit_limit) route to
     * the loan's borrower — shared across all of that borrower's loans —
     * everything else updates the loan itself. This keeps the existing
     * "Edit loan" form (one flat set of fields) working unchanged even
     * though the two halves now live in different tables.
     */
    public function update(Request $request, Loan $loan)
    {
        if ($request->hasAny(['name', 'username', 'email', 'password', 'phone', 'location', 'credit_limit'])) {
            $identity = $this->validatedIdentity($request, $loan->borrower);
            if (empty($identity['password'])) {
                unset($identity['password']);
            }
            $loan->borrower->update($identity);
        }

        $loan->update($this->validatedLoanFields($request));

        return response()->json($loan->fresh());
    }

    /**
     * Remove the specified resource from storage. Only this loan — the
     * borrower and their other loans are untouched.
     */
    public function destroy(Loan $loan)
    {
        $loan->delete();

        return response()->json(null, 204);
    }

    /**
     * Record a payment against this loan: logs it to the ledger and adds it
     * to total_paid. This is the intended way to reflect a borrower's
     * payment — not editing total_paid directly.
     */
    public function recordPayment(Request $request, Loan $loan)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|nullable|string',
            'paid_at' => 'sometimes|date',
        ]);

        $payment = $loan->recordPayment(
            (float) $validated['amount'],
            $validated['note'] ?? null,
            $request->user()->id,
            isset($validated['paid_at']) ? \Illuminate\Support\Carbon::parse($validated['paid_at']) : null
        );

        return response()->json([
            'loan' => $loan->fresh(),
            'payment' => $payment,
        ], 201);
    }

    /**
     * The computed daily interest breakdown merged with recorded payments,
     * for the admin to review the same ledger the borrower sees.
     */
    public function history(Loan $loan)
    {
        return response()->json($loan->history());
    }

    /**
     * Borrower identity fields — validated against `borrowers`, not `loans`,
     * now that they live there. `$ignore` is the borrower being updated (if
     * any), so its own username doesn't collide with itself.
     */
    private function validatedIdentity(Request $request, ?Borrower $ignore = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:255', Rule::unique('borrowers', 'username')->ignore($ignore?->id)],
            'email' => 'sometimes|nullable|email|max:255',
            'password' => 'sometimes|nullable|string|min:6',
            'phone' => 'sometimes|nullable|string|max:30',
            'location' => 'sometimes|nullable|string|max:255',
            'credit_limit' => 'sometimes|nullable|numeric|min:0',
        ]);
    }

    /**
     * Loan terms only. total_loan/start_date/due_date are `sometimes` (not
     * `required`) so "Add Client" can create a borrower with no loan yet,
     * and updating a loan's terms doesn't force every field to be resent.
     */
    private function validatedLoanFields(Request $request): array
    {
        return $request->validate([
            'total_loan' => 'sometimes|numeric|min:0',
            'total_paid' => 'sometimes|numeric|min:0',
            'interest_rate' => 'sometimes|numeric|min:0|max:100',
            'repayment_plan' => ['sometimes', Rule::exists('repayment_plans', 'key')],
            'installments_enabled' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'paid', 'overdue', 'defaulted', 'cancelled'])],
            'notes' => 'sometimes|nullable|string',
            'start_date' => 'sometimes|nullable|date',
            'due_date' => 'sometimes|nullable|date|after_or_equal:start_date',
        ]);
    }
}
