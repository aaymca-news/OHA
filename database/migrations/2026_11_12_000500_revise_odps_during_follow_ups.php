<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revising the ODP during the follow-ups (decided 12 Nov 2026). Once the assessment is
 * closed, the ODP approved in Stage 1 stays as it was; while a follow-up is open, a
 * revised ODP (uploaded, or taken from Google Drive) is kept as a REVISION, counted in
 * that follow-up, with no separate approval: closing the follow-up is the sign-off.
 *
 *   - documents.purpose 'revision', and the follow-up it came in;
 *   - odp_tasks: a task the revised ODP no longer has is REMOVED (kept for the record,
 *     out of the plan), never deleted;
 *   - follow_up_windows: the ODP version in force when the follow-up was closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('uploaded', 'after_signing', 'revision'))");
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('follow_up_number')->nullable()->comment('A revision: the follow-up it came in');
        });

        Schema::table('odp_tasks', function (Blueprint $table) {
            $table->timestampTz('removed_at')->nullable()->comment('No longer in the revised ODP: kept for the record, out of the plan');
            $table->foreignId('removed_in_document_id')->nullable()->comment('The revision it was removed in')->constrained('documents')->nullOnDelete();
        });

        Schema::table('follow_up_windows', function (Blueprint $table) {
            $table->foreignId('odp_document_id')->nullable()->comment('The ODP version in force when it was closed')->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_windows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('odp_document_id');
        });
        Schema::table('odp_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_in_document_id');
            $table->dropColumn('removed_at');
        });
        DB::table('documents')->where('purpose', 'revision')->delete();
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('follow_up_number');
        });
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('uploaded', 'after_signing'))");
    }
};
