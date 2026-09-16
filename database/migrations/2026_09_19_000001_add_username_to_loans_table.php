<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });

        // Backfill existing rows so username can become required below.
        DB::table('loans')->whereNull('username')->orderBy('id')->get()->each(function ($loan) {
            $base = Str::slug($loan->email ? Str::before($loan->email, '@') : $loan->name, '_') ?: 'borrower';
            $username = $base;
            $suffix = 1;
            while (DB::table('loans')->where('username', $username)->where('id', '!=', $loan->id)->exists()) {
                $username = $base.$suffix++;
            }
            DB::table('loans')->where('id', $loan->id)->update(['username' => $username]);
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
        });

        // Email is no longer the login identifier — the earlier unique
        // constraint (added when it was) is no longer needed.
        Schema::table('loans', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('username');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
