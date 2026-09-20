<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined repayment plans. The two built-in plans that used to be a
 * hardcoded enum ('3_day', 'weekly') become the first two rows, keeping the
 * same keys so every existing loan and request keeps pointing at a real plan.
 * loans.repayment_plan / loan_requests.plan turn from enums into plain
 * strings holding a plan key. Also adds the per-loan switch for whether the
 * borrower pays in installments at all (off = no late fee, no push-out).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repayment_plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedSmallInteger('period_days');
            $table->unsignedSmallInteger('installments');
            $table->decimal('daily_rate', 6, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('repayment_plans')->insert([
            ['key' => '3_day', 'name' => '3-Day Installment Plan', 'period_days' => 3, 'installments' => 5, 'daily_rate' => 0.25, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'weekly', 'name' => '1-Week Installment Plan', 'period_days' => 7, 'installments' => 5, 'daily_rate' => 0.40, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Postgres enums are varchar + a CHECK constraint; drop it, then widen to a plain string.
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_repayment_plan_check');
        DB::statement('ALTER TABLE loan_requests DROP CONSTRAINT IF EXISTS loan_requests_plan_check');
        DB::statement('ALTER TABLE loans ALTER COLUMN repayment_plan TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE loan_requests ALTER COLUMN plan TYPE VARCHAR(255)');

        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('installments_enabled')->default(true)->after('repayment_plan');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('installments_enabled');
        });
        Schema::dropIfExists('repayment_plans');
    }
};
