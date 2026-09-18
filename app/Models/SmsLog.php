<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per outgoing SMS attempt, success or failure — see
 * SmsGatewayService::send(), which is the single place these are written, so
 * every SMS this app ever sends (due-date reminders, custom messages, the
 * welcome text, payment confirmations) is auditable from one table.
 */
class SmsLog extends Model
{
    protected $fillable = ['loan_id', 'phone', 'message', 'success', 'error'];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
