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

        $loan = Loan::create($validated);

        return response()->json($loan, 201);
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
        $validated = $this->validated($request);

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

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'password' => 'sometimes|nullable|string|min:6',
            'phone' => 'sometimes|nullable|string|max:30',
            'location' => 'sometimes|nullable|string|max:255',
            'total_loan' => 'required|numeric|min:0',
            'total_paid' => 'sometimes|numeric|min:0',
            'interest_rate' => 'sometimes|numeric|min:0|max:100',
            'status' => ['sometimes', Rule::in(['pending', 'active', 'paid', 'overdue', 'defaulted', 'cancelled'])],
            'notes' => 'sometimes|nullable|string',
            'start_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:start_date',
        ]);
    }
}
