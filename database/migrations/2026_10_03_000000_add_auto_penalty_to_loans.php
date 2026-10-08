<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic weekly (per-plan-period) late fee — see docs/loans.md's
 * "Automatic weekly penalty" section. `auto_penalty` defaults to true for
 * every loan created from here on; every loan that already exists when this
 * runs is explicitly set to false, so existing loans keep today's manual
 * "press Notify to charge the fee" behavior and nothing gets charged
 * retroactively the first time one is opened.
 *
 * `auto_penalty_from` is the first date a missed deadline can be charged
 * for: set to the day the loan was created (or the day the option was
 * turned on), so entering a back-dated loan, or switching this on for an
 * older one, never bills weeks that passed before the option existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('auto_penalty')->default(true)->after('installments_enabled');
            $table->date('auto_penalty_from')->nullable()->after('auto_penalty');
        });

        DB::table('loans')->update(['auto_penalty' => false]);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['auto_penalty', 'auto_penalty_from']);
        });
    }
};
