<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProof extends Model
{
    protected $fillable = [
        'loan_id',
        'amount',
        'file_path',
        'file_url',
        'status',
        'note',
        'reviewed_by',
        'reviewed_at',
        'loan_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Deliberately not named reviewedBy() — that would serialize to the JSON
     * key "reviewed_by", silently shadowing the raw reviewed_by FK column
     * whenever this relation is eager-loaded.
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function loanPayment()
    {
        return $this->belongsTo(LoanPayment::class);
    }
}
