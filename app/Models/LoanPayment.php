<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanPayment extends Model
{
    protected $fillable = [
        'loan_id',
        'amount',
        'note',
        'paid_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Deliberately not named recordedBy() — that would serialize to the JSON
     * key "recorded_by", silently shadowing the raw recorded_by FK column
     * whenever this relation is eager-loaded.
     */
    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
