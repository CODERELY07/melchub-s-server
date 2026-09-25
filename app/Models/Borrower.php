<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

/**
 * A borrower's identity/login — split out from Loan (see the
 * split_borrowers_from_loans migration) so one person can have more than
 * one Loan over time (pay one off, borrow again later) without either
 * reusing the old loan row or hitting a duplicate-username error. This is
 * now what a borrower actually logs in as; Loan is purely loan terms.
 */
class Borrower extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait, HasApiTokens, SoftDeletes;

    // terms_accepted_at/terms_signature_name are system-managed (see
    // Api\BorrowerAuthController::acceptTerms()), so deliberately left out.
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone',
        'location',
        'credit_limit',
        'created_by',
    ];

    protected $hidden = [
        'password',
    ];

    protected $appends = [
        'available_credit',
        'outstanding_principal',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'credit_limit' => 'decimal:2',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    public function loanRequests()
    {
        return $this->hasMany(LoanRequest::class);
    }

    /** Every payment proof across all of this borrower's loans, newest first. */
    public function paymentProofs()
    {
        return $this->hasManyThrough(PaymentProof::class, Loan::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Unpaid principal (principal minus payments) summed across every loan
     * of this borrower's that isn't closed — what actually counts against
     * their credit_limit. Mirrors Loan::outstandingPrincipal() per-loan.
     */
    protected function outstandingPrincipal(): Attribute
    {
        return Attribute::get(
            fn () => (float) $this->loans()
                ->whereNotIn('status', Loan::CLOSED_STATUSES)
                ->selectRaw('COALESCE(SUM(CASE WHEN total_loan > total_paid THEN total_loan - total_paid ELSE 0 END), 0) AS outstanding')
                ->value('outstanding')
        );
    }

    /**
     * How much more this borrower can take out across ALL their loans
     * combined: the smaller of (a) their own credit_limit minus what they
     * already have out, and (b) what's left in the admin's shared lending
     * budget (Loan::remainingBudget(), unchanged — it already sums across
     * every loan system-wide regardless of borrower). Either cap may be
     * unset; null means neither is configured.
     */
    protected function availableCredit(): Attribute
    {
        return Attribute::get(function () {
            $caps = [];

            if ($this->credit_limit !== null) {
                $caps[] = ((float) $this->credit_limit) - $this->outstanding_principal;
            }

            $pool = Loan::remainingBudget();
            if ($pool !== null) {
                $caps[] = $pool;
            }

            return $caps === [] ? null : max(0, round(min($caps), 2));
        });
    }
}
