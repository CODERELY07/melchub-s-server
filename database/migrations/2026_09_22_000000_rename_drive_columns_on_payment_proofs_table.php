<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_proofs.drive_file_id/drive_file_url were named for the original
 * Google Drive integration. Now that uploads go to Supabase Storage instead
 * (see docs/sms-and-payments.md), renaming them to provider-agnostic names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->renameColumn('drive_file_id', 'file_path');
            $table->renameColumn('drive_file_url', 'file_url');
        });
    }

    public function down(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->renameColumn('file_path', 'drive_file_id');
            $table->renameColumn('file_url', 'drive_file_url');
        });
    }
};
