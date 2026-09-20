<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanRequest;
use App\Models\RepaymentPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RepaymentPlanController extends Controller
{
    /** Any signed-in user (borrowers need it for the request form); only staff also see inactive plans. */
    public function index(Request $request)
    {
        $query = RepaymentPlan::query()->orderBy('period_days')->orderBy('id');

        if (! ($request->user() instanceof User)) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $base = Str::slug($validated['name'], '_') ?: 'plan';
        $key = $base;
        for ($i = 2; RepaymentPlan::where('key', $key)->exists(); $i++) {
            $key = "{$base}_{$i}";
        }

        return response()->json(RepaymentPlan::create($validated + ['key' => $key])->fresh(), 201);
    }

    public function update(Request $request, RepaymentPlan $repaymentPlan)
    {
        $repaymentPlan->update($this->validated($request));

        return response()->json($repaymentPlan->fresh());
    }

    /** A plan any loan or request still uses can only be deactivated, never deleted. */
    public function destroy(RepaymentPlan $repaymentPlan)
    {
        $inUse = Loan::withTrashed()->where('repayment_plan', $repaymentPlan->key)->exists()
            || LoanRequest::where('plan', $repaymentPlan->key)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'This plan is in use by a loan or request. Deactivate it instead.',
            ], 422);
        }

        $repaymentPlan->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'period_days' => 'required|integer|min:1|max:365',
            'installments' => 'required|integer|min:1|max:120',
            'daily_rate' => 'required|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);
    }
}
