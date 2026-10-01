<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One real, persisted row per calendar day a compounding loan (see
 * `Loan::$compounds_interest`) has been open — created lazily by
 * `Loan::catchUpInterest()` the next time anyone touches the loan, not by a
 * cron (see docs/loans.md). `running_balance` is principal plus every
 * compounded entry up to and including this one; `interest_amount` is just
 * that day's own addition.
 */
class LoanInterestEntry extends Model
{
    protected $fillable = ['loan_id', 'entry_date', 'interest_amount', 'running_balance'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'interest_amount' => 'decimal:2',
            'running_balance' => 'decimal:2',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
