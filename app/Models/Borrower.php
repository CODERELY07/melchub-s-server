<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use DomainException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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

    /**
     * Every payment proof this borrower has sent — both the ones for one
     * loan and the "all my loans" ones (loan_id null), which only belong to
     * the borrower.
     */
    public function paymentProofs()
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The loans one payment for "all my loans" can go to: not closed and
     * still owing something, soonest due date first (loans with no due
     * date last, then oldest first).
     *
     * @return Collection<int, Loan>
     */
    public function loansForPayment(): Collection
    {
        return $this->loans()
            ->whereNotIn('status', Loan::CLOSED_STATUSES)
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Loan $loan) => $loan->balance > 0)
            ->values();
    }

    /**
     * How one amount is shared across $loans (already in the order from
     * loansForPayment()). Two passes, soonest due first each time:
     *   1. cover what each loan needs paid by its next deadline
     *      (amount_due_this_week — includes anything already late), so no
     *      loan gets a late fee while the money went to another loan's
     *      later installments;
     *   2. whatever is left goes to the remaining balances, same order.
     * Worked in whole centavos so the shares always add up to exactly
     * $amount. Never gives a loan more than its balance.
     *
     * @param  Collection<int, Loan>  $loans
     * @return array<int, float> loan id => amount, in the order money was assigned
     */
    public static function splitPayment(Collection $loans, float $amount): array
    {
        $left = (int) round($amount * 100);
        $shares = [];

        $passes = [
            fn (Loan $loan) => (float) $loan->amount_due_this_week,
            fn (Loan $loan) => max(0.0, (float) $loan->balance),
        ];

        foreach ($passes as $capOf) {
            foreach ($loans as $loan) {
                if ($left <= 0) {
                    break 2;
                }

                $room = (int) round($capOf($loan) * 100) - ($shares[$loan->id] ?? 0);
                $take = min($left, max(0, $room));

                if ($take > 0) {
                    $shares[$loan->id] = ($shares[$loan->id] ?? 0) + $take;
                    $left -= $take;
                }
            }
        }

        return array_map(fn (int $cents) => round($cents / 100, 2), $shares);
    }

    /**
     * One payment for all of this borrower's loans: splits it with
     * splitPayment() and records each share through Loan::recordPayment(),
     * all in one transaction (either every share is saved or none). Refuses
     * more than the total still owed — the borrower can't overpay.
     *
     * @return list<array{loan_id: int, loan_number: string, amount: float, payment_id: int}>
     *
     * @throws DomainException when there is nothing owed or $amount is more than the total owed
     */
    public function recordPayment(float $amount, ?string $note, ?int $recordedBy, ?\DateTimeInterface $paidAt = null): array
    {
        return DB::transaction(function () use ($amount, $note, $recordedBy, $paidAt) {
            $loans = $this->loansForPayment()->keyBy('id');
            $owed = round((float) $loans->sum(fn (Loan $loan) => $loan->balance), 2);

            if ($owed <= 0) {
                throw new DomainException('This borrower has nothing left to pay.');
            }
            if ($amount > $owed + 0.009) {
                throw new DomainException('That is more than the total still owed (₱'.number_format($owed, 2).').');
            }

            $label = 'Part of ₱'.number_format($amount, 2).' paid for all loans'.($note ? " — {$note}" : '');
            $allocation = [];

            foreach (self::splitPayment($loans->values(), $amount) as $loanId => $share) {
                $loan = $loans[$loanId];
                $payment = $loan->recordPayment($share, $label, $recordedBy, $paidAt);

                $allocation[] = [
                    'loan_id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'amount' => $share,
                    'payment_id' => $payment->id,
                ];
            }

            return $allocation;
        });
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
