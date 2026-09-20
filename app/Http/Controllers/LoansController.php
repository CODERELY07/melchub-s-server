<?php

namespace App\Http\Controllers;

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
        $query = Loan::query()->latest();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('loan_number', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $this->validated($request);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $validated['created_by'] = $request->user()->id;

        // Newly created clients are active right away, not stuck in the
        // DB-default 'pending'; an explicit status (New loan form) wins.
        $validated['status'] ??= 'active';

        $loan = Loan::create($validated);

        // Fields not sent (e.g. total_loan/status when created via "Add
        // Client" with no loan terms yet) get their value from the DB
        // default, which the in-memory $loan from create() won't reflect
        // until re-fetched.
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
     * Update the specified resource in storage.
     */
    public function update(Request $request, Loan $loan)
    {
        $validated = $this->validated($request, $loan);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $loan->update($validated);

        return response()->json($loan->fresh());
    }

    /**
     * Remove the specified resource from storage.
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
     * total_loan/start_date/due_date used to be `required` — every loan had
     * to have its terms set the moment the account was created. They're now
     * `sometimes` (total_loan) / `sometimes|nullable` (the dates) instead,
     * so the "Add Client" page (client/app/admin/clients/new/page.tsx) can
     * create a bare account with no loan terms yet, leaving those for the
     * admin to fill in later via this same endpoint's PUT. This doesn't
     * change anything for the existing "New loan" modal, which still always
     * submits all three (they're `required` on that form) — see
     * docs/loans.md Part 0 for why a loosened-but-unused-by-existing-callers
     * validation change was preferred over a second, duplicate endpoint.
     */
    private function validated(Request $request, ?Loan $loan = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:255', Rule::unique('loans', 'username')->ignore($loan?->id)],
            'email' => 'sometimes|nullable|email|max:255',
            'password' => 'sometimes|nullable|string|min:6',
            'phone' => 'sometimes|nullable|string|max:30',
            'location' => 'sometimes|nullable|string|max:255',
            'total_loan' => 'sometimes|numeric|min:0',
            'credit_limit' => 'sometimes|nullable|numeric|min:0',
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
