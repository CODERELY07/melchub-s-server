<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much a client is allowed to owe at once, set by the admin — the
 * borrower's loan-request form (LoanRequestController) uses this to compute
 * how much more they're allowed to ask for (credit_limit - total_loan, per
 * the admin's own example). Nullable: an existing loan, or a bare account
 * from the "Add Client" page, has no limit configured until the admin sets
 * one, and that's a distinct state from "limit is zero."
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('credit_limit', 12, 2)->nullable()->after('total_loan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
        });
    }
};
