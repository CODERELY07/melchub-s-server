<?php

namespace App\Http\Controllers;

use App\Models\Borrower;
use Illuminate\Http\Request;

class BorrowersController extends Controller
{
    /**
     * Staff: every borrower, for the "give an existing borrower a new loan"
     * picker on the Loans page (client/app/admin/loans/page.tsx). Small
     * dataset in practice, so no pagination — optionally filtered by name/
     * username for a quick search box.
     */
    public function index(Request $request)
    {
        $query = Borrower::query()->orderBy('name');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get());
    }
}
