<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections written into the files themselves (8 Oct 2026):
 *
 * - An answer typed for the OHA form is also written into a copy of the workbook: the
 *   corrected copy is what is previewed and downloaded; the file as uploaded is kept.
 * - A fix typed for the report (a missing section, the author, the score from the form)
 *   is written into the Word file, saved as a new version made by the platform.
 * - A report finding can be marked as reviewed with a note; it stays so across versions.
 * - Once the Board Chairperson has signed the ODP, changes made to it in Google Drive are
 *   still noted, as copies "changed after signing", with what changed; they are not versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_uploads', function (Blueprint $table) {
            $table->string('edited_disk', 20)->nullable()->comment('The workbook with the answers typed in the platform written in');
            $table->string('edited_path')->nullable();
            $table->char('edited_sha256', 64)->nullable();
            $table->unsignedBigInteger('edited_size_bytes')->nullable();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->jsonb('changes')->nullable()->comment('Platform versions: the fixes written in. Changed after signing: what changed since the signed version');
        });
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_source_valid');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_source_valid CHECK (source IN ('upload', 'google_drive', 'platform'))");
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('reference', 'uploaded', 'after_signing'))");

        Schema::table('artefacts', function (Blueprint $table) {
            $table->jsonb('reviewed_findings')->nullable()->comment('Report: findings marked as reviewed, by finding key, with the note, who and when');
        });
    }

    public function down(): void
    {
        Schema::table('artefacts', function (Blueprint $table) {
            $table->dropColumn('reviewed_findings');
        });

        DB::table('documents')->where('purpose', 'after_signing')->delete();
        DB::table('documents')->where('source', 'platform')->update(['source' => 'upload']);
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('reference', 'uploaded'))");
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_source_valid');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_source_valid CHECK (source IN ('upload', 'google_drive'))");
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('changes');
        });

        Schema::table('form_uploads', function (Blueprint $table) {
            $table->dropColumn(['edited_disk', 'edited_path', 'edited_sha256', 'edited_size_bytes']);
        });
    }
};
