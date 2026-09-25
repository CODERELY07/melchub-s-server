<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined "money on hand" accounts (GCash, a bank account, cash in a
 * drawer, …) — free-form, the admin names and updates each one. Used only
 * to compute the Dashboard's "Money on hand" / "Money with interest"
 * totals (cash accounts + loaned-out principal, or + outstanding balance);
 * not tied to any loan or payment record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_accounts');
    }
};
