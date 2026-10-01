<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not every person the admin loans money to knows how (or wants) to use the
 * borrower portal — some are just a name and a loan to keep track of. This
 * lets `username` (and therefore `password`, already nullable) be left
 * blank for those: no login exists for them, they just show up in the
 * Loans list. Postgres allows any number of NULLs under a unique index
 * (unlike a plain value), so this doesn't relax the "no two real usernames
 * collide" guarantee at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('username')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
        });
    }
};
