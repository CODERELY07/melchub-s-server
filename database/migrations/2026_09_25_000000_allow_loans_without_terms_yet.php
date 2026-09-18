<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a Loan row represent a bare client account with no loan terms set up
 * yet — see LoansController::validated() and the "Add Client" page, which
 * only collects name/username/email/password/phone/location and leaves the
 * rest for the admin to fill in later (e.g. once a LoanRequest is accepted).
 * `total_loan` defaults to 0 rather than going nullable, since every
 * balance/interest calculation already reads it as a plain number; there's
 * no meaningful "no principal set" state distinct from "principal is 0".
 * `start_date`/`due_date` have no such natural default, so they go nullable
 * instead — `Loan::interestAmount()`/`dailyInterestEntries()` already treat
 * a null start_date as "hasn't started accruing," and `formatDate()` on the
 * frontend already renders a null date as "—".
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('total_loan', 12, 2)->default(0)->change();
            $table->date('start_date')->nullable()->change();
            $table->date('due_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('total_loan', 12, 2)->default(null)->change();
            $table->date('start_date')->nullable(false)->change();
            $table->date('due_date')->nullable(false)->change();
        });
    }
};
