<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use Illuminate\Http\Request;

/**
 * Admin-only CRUD for "money on hand" accounts (GCash, a bank account, cash
 * in a drawer, …) — see the create_cash_accounts_table migration. Freely
 * add/rename/delete; nothing else in the app references a specific one, so
 * there's no "in use" restriction like RepaymentPlanController has.
 */
class CashAccountController extends Controller
{
    public function index()
    {
        return response()->json(CashAccount::query()->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        return response()->json(CashAccount::create($this->validated($request)), 201);
    }

    public function update(Request $request, CashAccount $cashAccount)
    {
        $cashAccount->update($this->validated($request));

        return response()->json($cashAccount->fresh());
    }

    public function destroy(CashAccount $cashAccount)
    {
        $cashAccount->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0',
        ]);
    }
}
