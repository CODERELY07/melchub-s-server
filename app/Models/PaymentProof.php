<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProof extends Model
{
    protected $fillable = [
        'loan_id',
        'borrower_id',
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

    /** Null when the proof is for all of the borrower's loans at once. */
    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
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
