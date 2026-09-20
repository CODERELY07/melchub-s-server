<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class Loan extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait, HasApiTokens, SoftDeletes;

    // penalty_amount, closed_at, terms_accepted_at, terms_signature_name, and
    // last_notified_at are all system-managed (see booted() and
    // NotificationController/BorrowerAuthController), so deliberately left
    // out of $fillable.
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone',
        'location',
        'total_loan',
        'credit_limit',
        'total_paid',
        'interest_rate',
        'repayment_plan',
        'installments_enabled',
        'status',
        'notes',
        'start_date',
        'due_date',
        'created_by',
    ];

    protected $hidden = [
        'password',
    ];

    protected $appends = [
        'interest_amount',
        'balance',
        'is_overdue',
        'available_credit',
        'plan_name',
        'plan_period_days',
    ];

    /**
     * Statuses that stop interest from accruing any further.
     */
    public const CLOSED_STATUSES = ['paid', 'cancelled', 'defaulted'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'total_loan' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'installments_enabled' => 'boolean',
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
        });

        // remainingBudget() depends on every loan's principal and status.
        static::saved(fn () => self::flushBudgetCache());
        static::deleted(fn () => self::flushBudgetCache());
        static::restored(fn () => self::flushBudgetCache());
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
                && $this->due_date !== null
                && $this->due_date->lt(today())
        );
    }

    /**
     * How much more this client can borrow: the smaller of (a) their own
     * credit_limit minus what they already have out, and (b) what's left in
     * the admin's shared lending budget (Setting: lending_budget). Either cap
     * may be unset — only the ones that exist apply — and null (not zero)
     * means neither is configured, so the borrower's request form can tell
     * "nothing configured" apart from "limit reached." Measured against
     * total_loan (principal drawn), not balance, so interest/penalties never
     * eat into anyone's borrowing room.
     */
    protected function availableCredit(): Attribute
    {
        return Attribute::get(function () {
            $caps = [];

            if ($this->credit_limit !== null) {
                $caps[] = ((float) $this->credit_limit) - $this->outstanding_principal;
            }

            $pool = self::remainingBudget();
            if ($pool !== null) {
                $caps[] = $pool;
            }

            return $caps === [] ? null : max(0, round(min($caps), 2));
        });
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

    public function loanRequests()
    {
        return $this->hasMany(LoanRequest::class);
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
     * (or the day it closed, if it already has) — each worth the same flat
     * daily amount. This is what `interest_amount`/`balance` are built from,
     * not a separate display-only estimate.
     */
    public function dailyInterestEntries(): array
    {
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
                'date' => $penalty->charged_at->toDateString(),
                'amount' => (float) $penalty->amount,
                'note' => $penalty->reason,
            ];
        }

        usort($entries, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $entries;
    }
}
