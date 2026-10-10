<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a borrower send ONE GCash receipt for all their loans: such a receipt
 * belongs to the borrower, not to one loan, and is split across their loans
 * (soonest due first) when the admin approves it.
 *
 * Every receipt now records its borrower (backfilled below from its loan),
 * and `loan_id` becomes optional — empty means "for all my loans".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->foreignId('borrower_id')->nullable()->after('loan_id')->constrained('borrowers')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE payment_proofs SET borrower_id = loans.borrower_id
            FROM loans
            WHERE payment_proofs.loan_id = loans.id
        SQL);

        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->foreignId('loan_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Receipts for "all loans" have no single loan to go back to — park
        // them on the borrower's newest loan rather than lose them.
        DB::statement(<<<'SQL'
            UPDATE payment_proofs SET loan_id = (
                SELECT loans.id FROM loans WHERE loans.borrower_id = payment_proofs.borrower_id ORDER BY loans.id DESC LIMIT 1
            )
            WHERE loan_id IS NULL
        SQL);

        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->foreignId('loan_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('borrower_id');
        });
    }
};
