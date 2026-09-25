<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A borrower's own loans — split out from BorrowerAuthController now that a
 * borrower can have more than one Loan (see the split_borrowers_from_loans
 * migration). Every action here checks the loan actually belongs to the
 * signed-in borrower; there's no route-model-binding shortcut that would
 * let one borrower's token reach another's loan by guessing an id.
 */
class BorrowerLoansController extends Controller
{
    /** Every loan this borrower has ever had, most recent first. */
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->loans()->latest()->get()
        );
    }

    public function show(Request $request, Loan $loan)
    {
        $this->authorizeOwnership($request, $loan);

        return response()->json($loan);
    }

    /** The computed daily interest breakdown merged with recorded payments. */
    public function history(Request $request, Loan $loan)
    {
        $this->authorizeOwnership($request, $loan);

        return response()->json($loan->history());
    }

    private function authorizeOwnership(Request $request, Loan $loan): void
    {
        if ($loan->borrower_id !== $request->user()->id) {
            // 404, not 403 — doesn't confirm to a borrower that a given loan
            // id exists at all if it isn't theirs.
            throw new NotFoundHttpException();
        }
    }
}
