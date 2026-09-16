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

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'total_loan' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
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
    }

    protected function interestAmount(): Attribute
    {
        return Attribute::get(
            fn () => round(((float) $this->total_loan) * ((float) $this->interest_rate) / 100, 2)
        );
    }

    protected function balance(): Attribute
    {
        return Attribute::get(
            fn () => round(((float) $this->total_loan) + $this->interest_amount - ((float) $this->total_paid), 2)
        );
    }

    protected function isOverdue(): Attribute
    {
        return Attribute::get(
            // A loan only counts as overdue once the day after its due date has started.
            fn () => ! in_array($this->status, ['paid', 'cancelled', 'defaulted'], true)
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

    /**
     * The total interest owed over the loan's term, spread evenly across each
     * day from start_date up to today (capped at due_date). This is a display
     * breakdown only — it doesn't change `interest_amount`/`balance`, which
     * still assume the full interest is owed from day one.
     */
    public function dailyInterestEntries(): array
    {
        if (! $this->start_date || ! $this->due_date) {
            return [];
        }

        $start = $this->start_date->copy()->startOfDay();
        $termEnd = $this->due_date->copy()->startOfDay();
        $cutoff = $termEnd->lt(today()) ? $termEnd : today();

        $totalDays = max(1, $start->diffInDays($termEnd) + 1);
        $dailyAmount = round($this->interest_amount / $totalDays, 2);

        $entries = [];

        if ($cutoff->gte($start)) {
            $cursor = $start->copy();
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

        foreach ($this->payments()->with('recordedBy')->orderBy('paid_at')->get() as $payment) {
            $entries[] = [
                'type' => 'payment',
                'date' => $payment->paid_at->toDateString(),
                'amount' => (float) $payment->amount,
                'note' => $payment->note,
                'recorded_by' => $payment->recordedBy?->name,
            ];
        }

        usort($entries, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $entries;
    }
}
