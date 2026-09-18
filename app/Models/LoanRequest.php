<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A borrower asking for a new/renewed loan — deliberately lightweight and
 * separate from Loan itself. Accepting a request does NOT create or modify
 * any Loan financial fields (principal, rate, dates); the admin still sets
 * those up manually via the existing Loans page, exactly as before this
 * feature existed. This table only tracks the request, which repayment plan
 * the borrower is interested in, and the fact that they read the rules
 * before asking — see docs/loans.md Part 7.
 */
class LoanRequest extends Model
{
    protected $fillable = [
        'loan_id',
        'plan',
        'message',
        'rules_acknowledged_at',
        'status',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'rules_acknowledged_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Not reviewedBy() — see PaymentProof::reviewer() for why (eager-loading
     * a relation named the same as its own raw FK column silently shadows
     * the FK in JSON output).
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
