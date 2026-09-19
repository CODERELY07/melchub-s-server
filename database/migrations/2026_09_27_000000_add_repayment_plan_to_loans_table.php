<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which repayment cadence this loan is actually on — same two options as
 * LoanRequest::plan (3_day/weekly), but a separate field: a request is what
 * a borrower asked for, this is what the admin actually set up. Defaults to
 * 'weekly' so every existing loan keeps behaving exactly as it did before
 * this column existed (a fixed 1-week push-out on late payment), rather
 * than silently changing behavior for loans nobody has touched.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->enum('repayment_plan', ['3_day', 'weekly'])->default('weekly')->after('interest_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('repayment_plan');
        });
    }
};
