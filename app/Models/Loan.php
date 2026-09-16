<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Loan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
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
}
