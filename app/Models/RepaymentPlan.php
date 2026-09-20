<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepaymentPlan extends Model
{
    protected $fillable = ['key', 'name', 'period_days', 'installments', 'daily_rate', 'is_active'];

    protected function casts(): array
    {
        return [
            'period_days' => 'integer',
            'installments' => 'integer',
            'daily_rate' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    private static ?array $byKey = null;

    /** Plan by its `key`, memoized per request (Loan appends plan info to every row). */
    public static function lookup(?string $key): ?self
    {
        self::$byKey ??= static::query()->get()->keyBy('key')->all();

        return $key === null ? null : (self::$byKey[$key] ?? null);
    }

    public static function flushCache(): void
    {
        self::$byKey = null;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }
}
