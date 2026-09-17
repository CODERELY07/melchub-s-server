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
        'total_paid',
        'interest_rate',
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
            'total_paid' => 'decimal:2',
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

    protected function isOverdue(): Attribute
    {
        return Attribute::get(
            // A loan only counts as overdue once the day after its due date has started.
            fn () => ! in_array($this->status, self::CLOSED_STATUSES, true)
                && $this->due_date !== null
                && $this->due_date->lt(today())
        );
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
