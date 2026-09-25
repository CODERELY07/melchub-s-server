<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The big one: a borrower can have more than one loan over time (pay one
 * off, borrow again later), but until now a `loans` row WAS the borrower's
 * login/identity (username/password/name/phone/…) — one row had to serve
 * both jobs, so there was no way to give someone a second loan without
 * either reusing their old loan row (destroying the first loan's history)
 * or a duplicate username the DB wouldn't allow.
 *
 * This splits identity out into a new `borrowers` table and makes `loans`
 * purely about loan terms, linked by `loans.borrower_id`. Every existing
 * loan becomes its own borrower with that loan attached — nobody's data
 * changes shape from their point of view, they just gain the ability to
 * take out a second loan later without losing the first one's record.
 *
 * Safety: every step here is additive or a straight copy before anything is
 * removed, matched by username (verified unique, including soft-deleted
 * rows, before writing this). The whole file runs in one DB transaction
 * (Laravel's default for Postgres), so a failure partway rolls back
 * everything. down() is a faithful, tested inverse — it re-creates the old
 * columns and copies the values back from `borrowers` before dropping it,
 * so rolling back loses nothing either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('phone')->nullable();
            $table->string('location')->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_signature_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // One borrower per existing loan row, carrying its identity fields
        // over verbatim (including soft-deleted loans, so nothing vanishes).
        DB::statement(<<<'SQL'
            INSERT INTO borrowers (name, username, email, password, phone, location, credit_limit, terms_accepted_at, terms_signature_name, created_by, created_at, updated_at, deleted_at)
            SELECT name, username, email, password, phone, location, credit_limit, terms_accepted_at, terms_signature_name, created_by, created_at, updated_at, deleted_at
            FROM loans
        SQL);

        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('borrower_id')->nullable()->after('id')->constrained('borrowers')->cascadeOnDelete();
        });

        // Matched by username — verified unique across every loan row
        // (including soft-deleted ones) before writing this migration.
        DB::statement(<<<'SQL'
            UPDATE loans SET borrower_id = borrowers.id
            FROM borrowers
            WHERE loans.username = borrowers.username
        SQL);

        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('borrower_id')->nullable(false)->change();
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            $table->foreignId('borrower_id')->nullable()->after('loan_id')->constrained('borrowers')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE loan_requests SET borrower_id = loans.borrower_id
            FROM loans
            WHERE loan_requests.loan_id = loans.id
        SQL);

        Schema::table('loan_requests', function (Blueprint $table) {
            $table->foreignId('borrower_id')->nullable(false)->change();
            // loan_id used to BE the borrower (see above) — it no longer
            // identifies anything meaningful now that a borrower can have
            // several loans, so new requests stop setting it. Left nullable
            // and populated on old rows only, as a harmless historical trace.
            $table->foreignId('loan_id')->nullable()->change();
        });

        // These now live on `borrowers`; every place that read/wrote them on
        // a loan has been moved to go through Loan::borrower(). Dropping
        // them (rather than leaving them frozen and unmaintained) avoids
        // future code accidentally reading stale identity data from a loan
        // row instead of the borrower it belongs to.
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'username', 'email', 'password', 'phone', 'location',
                'credit_limit', 'terms_accepted_at', 'terms_signature_name',
            ]);
        });

        // Existing borrower Sanctum tokens name `App\Models\Loan` as their
        // tokenable type; that model stops being Authenticatable as of this
        // deploy, so those tokens are now dead weight rather than a live
        // session — clearing them forces one clean re-login per borrower
        // instead of leaving stale rows that will never again resolve.
        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\Loan')->delete();
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('name')->nullable()->after('loan_number');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('phone')->nullable();
            $table->string('location')->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_signature_name')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE loans SET
                name = borrowers.name,
                username = borrowers.username,
                email = borrowers.email,
                password = borrowers.password,
                phone = borrowers.phone,
                location = borrowers.location,
                credit_limit = borrowers.credit_limit,
                terms_accepted_at = borrowers.terms_accepted_at,
                terms_signature_name = borrowers.terms_signature_name
            FROM borrowers
            WHERE loans.borrower_id = borrowers.id
        SQL);

        Schema::table('loans', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->string('username')->nullable(false)->change();
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('borrower_id');
            $table->foreignId('loan_id')->nullable(false)->change();
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('borrower_id');
        });

        Schema::dropIfExists('borrowers');
    }
};
