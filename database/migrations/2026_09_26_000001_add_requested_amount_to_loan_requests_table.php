<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much the borrower actually asked to borrow — added after the original
 * loan_requests migration (2026_09_24_000000) had already run, hence a new
 * migration rather than editing that one, per this repo's convention.
 * Nullable at the DB level only so existing pre-this-change rows don't
 * break; every new request from here on always sends it (see
 * LoanRequestController::store()'s validation).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loan_requests', function (Blueprint $table) {
            $table->decimal('requested_amount', 12, 2)->nullable()->after('plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_requests', function (Blueprint $table) {
            $table->dropColumn('requested_amount');
        });
    }
};
