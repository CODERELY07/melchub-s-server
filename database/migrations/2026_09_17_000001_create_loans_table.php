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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_number')->unique()->nullable();

            // Borrower details
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('phone')->nullable();
            $table->string('location')->nullable();

            // Loan terms
            $table->decimal('total_loan', 12, 2);
            $table->decimal('total_paid', 12, 2)->default(0);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->enum('status', ['pending', 'active', 'paid', 'overdue', 'defaulted', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->date('start_date');
            $table->date('due_date');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
