<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // The date interest accrual stopped (loan marked paid/cancelled/
            // defaulted). Without this, a "closed" loan's balance would keep
            // growing every day after the fact, since interest is computed
            // from elapsed days rather than a fixed one-time amount.
            $table->date('closed_at')->nullable()->after('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
