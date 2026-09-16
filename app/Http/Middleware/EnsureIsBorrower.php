<?php

namespace App\Http\Middleware;

use App\Models\Loan;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsBorrower
{
    /**
     * Sanctum tokens are polymorphic — a staff `User` token and a borrower
     * `Loan` token both pass `auth:sanctum` the same way. This confirms the
     * resolved token actually belongs to a Loan before borrower-only routes
     * (and controller code that assumes $request->user() is a Loan) run.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Loan) {
            abort(403, 'This endpoint is only available to borrower accounts.');
        }

        return $next($request);
    }
}
