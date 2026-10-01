<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily compound interest, recorded as real rows instead of calculated live
 * — see docs/loans.md's "Daily compound interest" section. `loans` gets a
 * `compounds_interest` flag: the column defaults to true so every loan
 * created from here on compounds by default, but every loan that already
 * exists when this migration runs is explicitly set to false right below —
 * they keep today's simple-interest math exactly as agreed with those
 * borrowers, nothing about an already-open loan changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('compounds_interest')->default(true)->after('interest_rate');
        });

        DB::table('loans')->update(['compounds_interest' => false]);

        Schema::create('loan_interest_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('interest_amount', 12, 2);
            // Principal + every compounded interest entry up to and including
            // this one — what interest_amount/balance read for a compounding
            // loan, so they're a lookup, not a recalculation, once recorded.
            $table->decimal('running_balance', 12, 2);
            $table->timestamps();
            $table->unique(['loan_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_interest_entries');

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('compounds_interest');
        });
    }
};
