<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanPenalty extends Model
{
    protected $fillable = [
        'loan_id',
        'amount',
        'reason',
        'charged_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'charged_at' => 'date',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
