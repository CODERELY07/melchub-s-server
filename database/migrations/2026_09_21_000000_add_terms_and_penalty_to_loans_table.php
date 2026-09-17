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
            // Terms & conditions e-signature (typed full name), accepted once
            // by the borrower on their first portal login.
            $table->timestamp('terms_accepted_at')->nullable()->after('closed_at');
            $table->string('terms_signature_name')->nullable()->after('terms_accepted_at');

            // Accumulates late-fee charges applied via the "notify" SMS action
            // (kept separate from interest_rate, which is a pure daily accrual).
            $table->decimal('penalty_amount', 12, 2)->default(0)->after('terms_signature_name');

            // For the admin UI ("already notified today") — purely informational.
            $table->timestamp('last_notified_at')->nullable()->after('penalty_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_signature_name', 'penalty_amount', 'last_notified_at']);
        });
    }
};
