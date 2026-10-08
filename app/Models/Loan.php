<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loan terms only — a borrower's identity/login lives on Borrower (see the
 * split_borrowers_from_loans migration and docs/loans.md). name/username/
 * email/phone/location/credit_limit below are read-only pass-throughs onto
 * $this->borrower, kept so the many places that already read `$loan->name`
 * etc. (admin JSON, SMS text) didn't all need rewriting — they're no longer
 * real columns on this table, so eager-load `borrower` before reading them
 * in a loop or each row costs its own query.
 */
class Loan extends Model
{
    use SoftDeletes;

    // penalty_amount, closed_at, and last_notified_at are all system-managed
    // (see booted() and NotificationController), so deliberately left out.
    protected $fillable = [
        'borrower_id',
        'total_loan',
        'total_paid',
        'interest_rate',
        'compounds_interest',
        'repayment_plan',
        'installments_enabled',
        'auto_penalty',
        'status',
        'notes',
        'start_date',
        'due_date',
        'created_by',
    ];

    // The eager-loaded borrower is only there to feed the flat name/phone/…
    // accessors below; serializing it too would run all of Borrower's own
    // computed attributes (available_credit, outstanding_principal) — two
    // extra queries per loan in every list — for data already in the JSON.
    protected $hidden = ['borrower'];

    protected $appends = [
        'interest_amount',
        'balance',
        'is_overdue',
        'installment_amount',
        'amount_due_this_week',
        'past_due_amount',
        'plan_name',
        'plan_period_days',
        'name',
        'username',
        'email',
        'phone',
        'location',
        'credit_limit',
    ];

    /**
     * Statuses that stop interest from accruing any further.
     */
    public const CLOSED_STATUSES = ['paid', 'cancelled', 'defaulted'];

    protected function casts(): array
    {
        return [
            'total_loan' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'installments_enabled' => 'boolean',
            'compounds_interest' => 'boolean',
            'auto_penalty' => 'boolean',
            'auto_penalty_from' => 'date',
            'interest_rate' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
            'closed_at' => 'date',
            'terms_accepted_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Loan $loan) {
            // loan_number is system-generated (not mass-assignable), so bypass
            // the fillable guard here instead of exposing it for user input.
            $loan->forceFill([
                'loan_number' => 'LN-'.str_pad((string) $loan->id, 6, '0', STR_PAD_LEFT),
            ])->saveQuietly();
        });

        // closed_at is managed automatically from status, not user-editable:
        // freeze interest accrual the moment a loan is settled, and resume it
        // if a closed loan is ever manually reopened.
        static::saving(function (Loan $loan) {
            $isClosed = in_array($loan->status, self::CLOSED_STATUSES, true);
            if ($isClosed && ! $loan->closed_at) {
                $loan->closed_at = today();
            } elseif (! $isClosed && $loan->closed_at) {
                $loan->closed_at = null;
            }

            // Automatic late fees only ever bill deadlines from the day the
            // option started applying (a new loan, or it being switched on)
            // — never weeks that passed before it did.
            $turningOnAutoPenalty = $loan->auto_penalty !== false
                && (! $loan->exists || $loan->isDirty('auto_penalty'));
            if ($turningOnAutoPenalty) {
                $loan->auto_penalty_from = today();
            }

            // Recorded compound-interest entries were calculated from the
            // old principal/rate/start date (or while compounding was on).
            // Changing any of those makes them wrong, so drop them — the
            // next catch-up rebuilds them from start_date under the new terms.
            if ($loan->exists && $loan->isDirty(['total_loan', 'interest_rate', 'start_date', 'compounds_interest'])) {
                $loan->interestEntries()->delete();
                $loan->latestRunningBalance = null;
            }
        });

        // remainingBudget() depends on every loan's principal and status.
        static::saved(fn () => self::flushBudgetCache());
        static::deleted(fn () => self::flushBudgetCache());
        static::restored(fn () => self::flushBudgetCache());

        // No cron on this app's hosting (see docs/loans.md) — instead, the
        // next time ANYONE loads this loan (admin or borrower, list or
        // single), catch its real daily interest entries up to today. Only
        // does anything for a compounding loan that isn't already caught
        // up, so this is a cheap no-op the rest of the time.
        static::retrieved(function (Loan $loan) {
            $loan->catchUpInterest();
            $loan->catchUpPenalties();
        });
    }

    private function usesAutoPenalty(): bool
    {
        return $this->auto_penalty
            && $this->installments_enabled
            && ! in_array($this->status, self::CLOSED_STATUSES, true);
    }

    private function planInstallments(): int
    {
        return max(1, RepaymentPlan::lookup($this->repayment_plan)?->installments ?? 5);
    }

    /**
     * What one period's payment is: an even share of the principal plus
     * that period's interest — the same figure the borrower's Pay page has
     * always shown. Nominal (simple-interest) even for a compounding loan.
     */
    protected function installmentAmount(): Attribute
    {
        return Attribute::get(fn () => round(
            ((float) $this->total_loan) * (1 / $this->planInstallments() + (((float) $this->interest_rate) / 100) * $this->plan_period_days),
            2
        ));
    }

    /** Which week's deadline $deadline is (1 = first), counted from start_date. */
    private function deadlineIndex(Carbon $deadline): int
    {
        if (! $this->start_date) {
            return 1;
        }

        $days = (int) round($this->start_date->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false));

        return max(1, (int) ceil($days / max(1, $this->plan_period_days)));
    }

    /** Cumulative amount that should have been paid by deadline number $index (capped at the plan's last installment). */
    private function scheduledTarget(int $index): float
    {
        return min($index, $this->planInstallments()) * $this->installment_amount;
    }

    /** total_paid as it stood at the end of $date — payments recorded with a later paid_at don't count yet. */
    private function paidAsOf(Carbon $date): float
    {
        $later = (float) $this->payments()->whereDate('paid_at', '>', $date->toDateString())->sum('amount');

        return (float) $this->total_paid - $later;
    }

    /**
     * Shortfall against every deadline that has already passed (every
     * deadline before the current due_date) — what's actually late, as
     * opposed to what's merely due by the next deadline.
     */
    protected function pastDueAmount(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->usesAutoPenalty() || ! $this->due_date) {
                return 0.0;
            }

            $passed = $this->deadlineIndex($this->due_date) - 1;
            if ($passed <= 0) {
                return 0.0;
            }

            $shortfall = $this->scheduledTarget($passed) - (float) $this->total_paid;

            return round(max(0.0, min($shortfall, max(0.0, (float) $this->balance))), 2);
        });
    }

    /**
     * What the borrower still needs to pay to be caught up as of the next
     * deadline — this week's installment plus any shortfall from earlier
     * weeks. Capped at the balance.
     */
    protected function amountDueThisWeek(): Attribute
    {
        return Attribute::get(function () {
            if (in_array($this->status, self::CLOSED_STATUSES, true)) {
                return 0.0;
            }

            $balance = max(0.0, (float) $this->balance);

            if (! $this->installments_enabled) {
                return round($balance, 2);
            }

            if (! $this->auto_penalty || ! $this->due_date) {
                return round(min($balance, $this->installment_amount), 2);
            }

            $target = $this->scheduledTarget($this->deadlineIndex($this->due_date));

            return round(max(0.0, min($balance, $target - (float) $this->total_paid)), 2);
        });
    }

    /**
     * For an automatic-penalty loan, walks every weekly deadline that has
     * passed since the loan was last looked at: if the total paid by that
     * deadline was below the cumulative amount due by then, charges the
     * admin-configured late fee (Settings → Late fee) as a real
     * LoanPenalty row; either way the due date rolls forward one period.
     * Runs on every load of the loan (see booted()) because this app's
     * hosting has no cron — and each week is claimed with a compare-and-swap
     * on due_date inside a transaction, so two requests opening the same
     * loan at once can never both charge the same week.
     *
     * Past the plan's last installment the target stops growing, so the fee
     * keeps being charged each week until the scheduled total is paid.
     */
    public function catchUpPenalties(): void
    {
        if (! $this->usesAutoPenalty()
            || ! in_array($this->status, ['active', 'overdue'], true)
            || ! $this->due_date
            || ! $this->start_date) {
            return;
        }

        $today = today();
        if (! $this->due_date->lt($today)) {
            return;
        }

        $periodDays = max(1, $this->plan_period_days);
        $fee = (float) Setting::get('late_fee_amount', '50');
        $billableFrom = $this->auto_penalty_from ?? $today;
        $deadline = $this->due_date->copy()->startOfDay();
        $guard = 0;

        while ($deadline->lt($today) && $guard++ < 400) {
            $next = $deadline->copy()->addDays($periodDays);
            $index = $this->deadlineIndex($deadline);
            $expected = $this->scheduledTarget($index);
            $paid = $this->paidAsOf($deadline);
            $missed = $fee > 0 && $deadline->gte($billableFrom) && $paid + 0.009 < $expected;

            $advanced = DB::transaction(function () use ($deadline, $next, $missed, $fee, $index, $paid, $expected) {
                $claimed = DB::table('loans')
                    ->where('id', $this->id)
                    ->whereDate('due_date', $deadline->toDateString())
                    ->update(['due_date' => $next->toDateString()]);

                if ($claimed !== 1) {
                    return false;
                }

                if ($missed) {
                    $this->penalties()->create([
                        'amount' => $fee,
                        'reason' => sprintf(
                            'Missed weekly payment (week %d, due %s): paid ₱%s of the ₱%s due by then',
                            $index,
                            $deadline->format('M d, Y'),
                            number_format(max(0, $paid), 2),
                            number_format($expected, 2)
                        ),
                        'charged_at' => $deadline->toDateString(),
                    ]);
                    DB::table('loans')->where('id', $this->id)->increment('penalty_amount', $fee);
                }

                return true;
            });

            if (! $advanced) {
                break;
            }

            $deadline = $next;
        }

        // Pull the new values into this in-memory instance without a full
        // re-fetch (which would re-enter the retrieved hook).
        $row = DB::table('loans')->where('id', $this->id)->first(['due_date', 'penalty_amount']);
        if ($row) {
            $this->setRawAttributes(array_merge($this->getAttributes(), [
                'due_date' => $row->due_date,
                'penalty_amount' => $row->penalty_amount,
            ]), true);
        }
    }

    /**
     * For a compounding loan, writes one real `LoanInterestEntry` row for
     * every day since the last recorded one (or since start_date, if none
     * exist yet) up through today or closed_at — each day's interest
     * calculated on the running balance *including* every prior day's
     * compounded interest, not the original principal. A no-op for a
     * simple-interest loan (compounds_interest false), a closed loan with
     * nothing left to accrue, or one already caught up to its cutoff.
     */
    public function catchUpInterest(): void
    {
        if (! $this->compounds_interest) {
            return;
        }

        $cutoff = $this->accrualCutoff();
        if (! $cutoff) {
            return;
        }

        $latest = $this->interestEntries()->orderByDesc('entry_date')->first();
        $cursor = $latest ? $latest->entry_date->copy()->addDay() : $this->start_date->copy()->startOfDay();
        $runningBalance = $latest ? (float) $latest->running_balance : (float) $this->total_loan;

        // The query above already told us the latest balance; remember it so
        // reading interest_amount/balance on this instance costs no more.
        $this->latestRunningBalance = [$latest ? (float) $latest->running_balance : null];

        if ($cursor->gt($cutoff)) {
            return;
        }

        $rate = (float) $this->interest_rate / 100;

        while ($cursor->lte($cutoff)) {
            $interest = round($runningBalance * $rate, 2);
            $runningBalance = round($runningBalance + $interest, 2);

            $this->interestEntries()->create([
                'entry_date' => $cursor->toDateString(),
                'interest_amount' => $interest,
                'running_balance' => $runningBalance,
            ]);

            $cursor->addDay();
        }

        $this->latestRunningBalance = [$runningBalance];
    }

    /**
     * Latest recorded running balance of a compounding loan, or null if no
     * entries exist yet. Memoized per instance: interest_amount, balance,
     * is_overdue, amount_due_this_week… all read it, and each one used to be
     * its own database query on every serialized loan — painful against a
     * remote database on a small free host.
     *
     * @var array{0: float|null}|null
     */
    private ?array $latestRunningBalance = null;

    private function currentRunningBalance(): ?float
    {
        if ($this->latestRunningBalance === null) {
            $latest = $this->interestEntries()->orderByDesc('entry_date')->first();
            $this->latestRunningBalance = [$latest ? (float) $latest->running_balance : null];
        }

        return $this->latestRunningBalance[0];
    }

    /**
     * interest_rate is a daily rate: this loan accrues this many pesos of
     * interest for every day it remains open, on the original principal.
     */
    private function dailyInterestAmount(): float
    {
        return round(((float) $this->total_loan) * ((float) $this->interest_rate) / 100, 2);
    }

    /**
     * The last day interest should count for — today, or the day the loan
     * was closed if it already has been. Null if the loan hasn't started yet.
     */
    private function accrualCutoff(): ?\Illuminate\Support\Carbon
    {
        if (! $this->start_date) {
            return null;
        }

        $cutoff = $this->closed_at ? $this->closed_at->copy()->startOfDay() : today();

        return $cutoff->lt($this->start_date) ? null : $cutoff;
    }

    protected function interestAmount(): Attribute
    {
        return Attribute::get(function () {
            if ($this->compounds_interest) {
                $running = $this->currentRunningBalance();

                return $running !== null ? round($running - (float) $this->total_loan, 2) : 0.0;
            }

            $cutoff = $this->accrualCutoff();
            if (! $cutoff) {
                return 0.0;
            }

            $days = $this->start_date->copy()->startOfDay()->diffInDays($cutoff) + 1;

            return round($this->dailyInterestAmount() * $days, 2);
        });
    }

    protected function balance(): Attribute
    {
        return Attribute::get(
            fn () => round(
                ((float) $this->total_loan) + $this->interest_amount + ((float) $this->penalty_amount) - ((float) $this->total_paid),
                2
            )
        );
    }

    /**
     * Principal still unpaid — what actually counts against a credit limit
     * or the lending budget. Payments free that money up again; interest and
     * late fees don't use any of it.
     */
    protected function outstandingPrincipal(): Attribute
    {
        return Attribute::get(
            fn () => max(0, round(((float) $this->total_loan) - ((float) $this->total_paid), 2))
        );
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->borrower?->name);
    }

    protected function username(): Attribute
    {
        return Attribute::get(fn () => $this->borrower?->username);
    }

    protected function email(): Attribute
    {
        return Attribute::get(fn () => $this->borrower?->email);
    }

    protected function phone(): Attribute
    {
        return Attribute::get(fn () => $this->borrower?->phone);
    }

    protected function location(): Attribute
    {
        return Attribute::get(fn () => $this->borrower?->location);
    }

    /** Read-only convenience for the admin UI — credit_limit is set on the borrower, shared across all their loans. */
    protected function creditLimit(): Attribute
    {
        return Attribute::get(fn () => $this->borrower?->credit_limit);
    }

    protected function planName(): Attribute
    {
        return Attribute::get(fn () => RepaymentPlan::lookup($this->repayment_plan)?->name);
    }

    /** Days per installment for this loan's plan (7 if the plan record is gone). */
    protected function planPeriodDays(): Attribute
    {
        return Attribute::get(fn () => RepaymentPlan::lookup($this->repayment_plan)?->period_days ?? 7);
    }

    protected function isOverdue(): Attribute
    {
        return Attribute::get(
            // A loan only counts as overdue once the day after its due date has started.
            fn () => ! in_array($this->status, self::CLOSED_STATUSES, true)
                && (
                    ($this->due_date !== null && $this->due_date->lt(today()))
                    // An automatic-penalty loan's due date rolls forward every
                    // week, so "behind" has to be measured by what's unpaid
                    // from weeks that already ended, not the date alone.
                    || $this->past_due_amount > 0
                )
        );
    }

    private static ?float $remainingBudgetCache = null;

    private static bool $remainingBudgetCached = false;

    /**
     * The shared lending pool minus unpaid principal (principal minus
     * payments) still out on every non-closed loan, or null if the admin hasn't set a budget. Memoized for
     * the request (available_credit is appended to every loan in a list, and
     * this would otherwise be two queries per row); cleared whenever a loan
     * changes or the budget setting is saved.
     */
    public static function remainingBudget(): ?float
    {
        if (! self::$remainingBudgetCached) {
            $budget = Setting::get('lending_budget');

            self::$remainingBudgetCache = ($budget === null || $budget === '')
                ? null
                : max(0, round(
                    (float) $budget - (float) static::query()
                        ->whereNotIn('status', self::CLOSED_STATUSES)
                        ->selectRaw('COALESCE(SUM(CASE WHEN total_loan > total_paid THEN total_loan - total_paid ELSE 0 END), 0) AS outstanding')
                        ->value('outstanding'),
                    2
                ));
            self::$remainingBudgetCached = true;
        }

        return self::$remainingBudgetCache;
    }

    public static function flushBudgetCache(): void
    {
        self::$remainingBudgetCached = false;
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class);
    }

    public function penalties()
    {
        return $this->hasMany(LoanPenalty::class);
    }

    public function paymentProofs()
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function smsLogs()
    {
        return $this->hasMany(SmsLog::class);
    }

    public function interestEntries()
    {
        return $this->hasMany(LoanInterestEntry::class);
    }

    /**
     * The single place a payment gets logged and reflected on the loan —
     * used by both the admin's direct "record payment" action and approving
     * an uploaded GCash payment proof, so the two can never drift apart.
     */
    public function recordPayment(float $amount, ?string $note, ?int $recordedBy, ?\DateTimeInterface $paidAt = null): LoanPayment
    {
        $payment = $this->payments()->create([
            'amount' => $amount,
            'note' => $note,
            'paid_at' => $paidAt ?? today(),
            'recorded_by' => $recordedBy,
        ]);

        $this->increment('total_paid', $amount);
        $this->refresh();

        if ($this->balance <= 0 && ! in_array($this->status, self::CLOSED_STATUSES, true)) {
            $this->update(['status' => 'paid']);
        }

        return $payment;
    }

    /**
     * The single place a penalty gets logged and reflected on the loan —
     * mirrors recordPayment(). Logging a LoanPenalty row alone would leave
     * `penalty_amount` (which `balance` actually reads) unchanged, so the
     * two must always be updated together.
     */
    public function chargePenalty(float $amount, ?string $reason = null, ?\DateTimeInterface $chargedAt = null): LoanPenalty
    {
        $penalty = $this->penalties()->create([
            'amount' => $amount,
            'reason' => $reason,
            'charged_at' => $chargedAt ?? today(),
        ]);

        $this->increment('penalty_amount', $amount);
        $this->refresh();

        return $penalty;
    }

    /**
     * One entry per day the loan has been open, from start_date up to today
     * (or the day it closed, if it already has). For a compounding loan
     * these are the real, persisted `LoanInterestEntry` rows (written by
     * catchUpInterest(), not recalculated here); for a simple-interest one
     * they're still computed on the fly as before — each worth the same
     * flat daily amount. This is what `interest_amount`/`balance` are built
     * from, not a separate display-only estimate.
     */
    public function dailyInterestEntries(): array
    {
        if ($this->compounds_interest) {
            return $this->interestEntries()
                ->orderBy('entry_date')
                ->get()
                ->map(fn (LoanInterestEntry $entry, int $i) => [
                    'type' => 'interest',
                    'date' => $entry->entry_date->toDateString(),
                    'day' => $i + 1,
                    'amount' => (float) $entry->interest_amount,
                    'note' => 'Compounded — balance now ₱'.number_format((float) $entry->running_balance, 2),
                ])
                ->all();
        }

        $cutoff = $this->accrualCutoff();
        if (! $cutoff) {
            return [];
        }

        $dailyAmount = $this->dailyInterestAmount();
        $entries = [];
        $cursor = $this->start_date->copy()->startOfDay();
        $day = 1;

        while ($cursor->lte($cutoff)) {
            $entries[] = [
                'type' => 'interest',
                'date' => $cursor->toDateString(),
                'day' => $day,
                'amount' => $dailyAmount,
                'note' => "Day {$day} interest",
            ];
            $cursor->addDay();
            $day++;
        }

        return $entries;
    }

    /**
     * The full ledger for this loan: computed daily interest entries merged
     * with real, recorded payments, sorted oldest to newest.
     */
    public function history(): array
    {
        $entries = $this->dailyInterestEntries();

        foreach ($this->payments()->with('recorder')->orderBy('paid_at')->get() as $payment) {
            $entries[] = [
                'type' => 'payment',
                'date' => $payment->paid_at->toDateString(),
                'amount' => (float) $payment->amount,
                'note' => $payment->note,
                'recorded_by' => $payment->recorder?->name,
            ];
        }

        foreach ($this->penalties()->orderBy('charged_at')->get() as $penalty) {
            $entries[] = [
                'type' => 'penalty',
                'id' => $penalty->id,
                'date' => $penalty->charged_at->toDateString(),
                'amount' => (float) $penalty->amount,
                'note' => $penalty->reason,
            ];
        }

        usort($entries, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $entries;
    }
}
