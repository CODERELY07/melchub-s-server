<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsStaff
{
    /**
     * Mirrors EnsureIsBorrower for the other direction. Without this, a
     * borrower (Loan) token hitting a staff route reaches Spatie's `role:`
     * middleware, which assumes the resolved user is Authorizable — Loan
     * isn't, so it throws a raw 500 instead of a clean 403.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            abort(403, 'This endpoint is only available to staff accounts.');
        }

        return $next($request);
    }
}
