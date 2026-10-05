<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ODP step (5 Oct 2026):
 *
 * - The ODP is no longer generated. Staff upload it in versions, like the report, and
 *   link the Google Drive document they write it in. The generated sections and the
 *   context typed for the generator go.
 * - When the platform has access to Google Drive, it takes each change made there as a
 *   new version by itself. A version records where it came from and who changed it.
 * - The Board Chairperson signs one exact version: the signature records which.
 * - Each automatic check of Google Drive is recorded, so a stuck sync is visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('document_sections');

        Schema::table('artefacts', function (Blueprint $table) {
            $table->dropColumn('author_context');
            $table->string('drive_file_id', 128)->nullable()->comment('ODP: the Google Drive document it is written in');
            $table->text('drive_url')->nullable()->comment('ODP: the link as the assessor gave it');
            $table->foreignId('drive_linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('drive_linked_at')->nullable();
            $table->bigInteger('drive_version')->nullable()->comment('Google Drive’s version number of the file when last read');
            $table->timestampTz('drive_checked_at')->nullable()->comment('When the platform last read the file in Google Drive');
            $table->text('drive_problem')->nullable()->comment('Why the last read of the file failed; empty once it succeeds');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('source', 16)->default('upload')->comment('upload: a file chosen by a user; google_drive: taken from the linked document');
            $table->bigInteger('drive_version')->nullable()->comment('Google Drive’s version number of the file this version was taken from');
            $table->string('edited_by_email')->nullable()->comment('Google Drive: the account that last changed the file');
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_source_valid CHECK (source IN ('upload', 'google_drive'))");

        // Nothing is generated any more; a file once generated is kept, for reference.
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        DB::table('documents')->where('purpose', 'generated')->update(['purpose' => 'reference']);
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('reference', 'uploaded'))");

        Schema::table('board_signatures', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('artefact_id')->comment('The ODP version signed')
                ->constrained()->nullOnDelete();
        });

        // Like the report, the ODP's gate is cleared by its first approved version: a newer
        // version waiting for approval does not reopen it.
        $this->milestones(<<<'SQL'
            COALESCE((SELECT MIN(od.approved_at) FROM documents od WHERE od.artefact_id = o.id),
                     CASE WHEN o.state = 'approved' THEN o.approved_at END)
            SQL);

        Schema::create('drive_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('checked')->default(0)->comment('Linked ODPs looked at');
            $table->unsignedInteger('saved')->default(0)->comment('New versions taken from Google Drive');
            $table->unsignedInteger('failed')->default(0)->comment('ODPs whose file could not be read');
            $table->text('error')->nullable()->comment('Why the whole run failed, e.g. no access to Google');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_sync_runs');

        $this->milestones("CASE WHEN o.state = 'approved' THEN o.approved_at END");

        Schema::table('board_signatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
        });

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('generated', 'reference', 'uploaded'))");
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_source_valid');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['source', 'drive_version', 'edited_by_email']);
        });

        Schema::table('artefacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('drive_linked_by');
            $table->dropColumn(['drive_file_id', 'drive_url', 'drive_linked_at', 'drive_version', 'drive_checked_at', 'drive_problem']);
            $table->text('author_context')->nullable();
        });

        Schema::create('document_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artefact_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->unsignedSmallInteger('sort_order');
            $table->string('title');
            $table->string('status', 20)->default('placeholder');
            $table->jsonb('content')->nullable();
            $table->timestampsTz();

            $table->unique(['artefact_id', 'key']);
        });
        DB::statement("ALTER TABLE document_sections ADD CONSTRAINT document_sections_status_valid CHECK (status IN ('placeholder', 'drafted', 'final'))");
    }

    /** v_assessment_milestones with the given moment the ODP's gate is cleared; everything else as before. */
    private function milestones(string $odpDone): void
    {
        DB::statement(<<<SQL
            CREATE OR REPLACE VIEW v_assessment_milestones AS
            SELECT a.id AS assessment_id,
                   g.id AS gate_id,
                   g.code AS gate_code,
                   g.milestone_label,
                   g.sort_order,
                   COALESCE(ti.due_on, a.opened_at::date + g.sla_days) AS due_on,
                   (ti.due_on IS NOT NULL) AS set_by_hand,
                   d.done_at,
                   (d.done_at IS NOT NULL AND d.done_at::date > COALESCE(ti.due_on, a.opened_at::date + g.sla_days)) AS completed_late,
                   (d.done_at IS NULL AND COALESCE(ti.due_on, a.opened_at::date + g.sla_days) < CURRENT_DATE) AS overdue
            FROM assessments a
            CROSS JOIN gates g
            JOIN artefacts f ON f.assessment_id = a.id AND f.kind = 'form'
            JOIN artefacts r ON r.assessment_id = a.id AND r.kind = 'report'
            JOIN artefacts o ON o.assessment_id = a.id AND o.kind = 'odp'
            LEFT JOIN board_signatures os ON os.artefact_id = o.id
            LEFT JOIN timeline_items ti ON ti.assessment_id = a.id AND ti.gate_id = g.id
            CROSS JOIN LATERAL (
                SELECT CASE g.code
                           WHEN 'form' THEN CASE WHEN f.state = 'approved' THEN f.approved_at END
                           WHEN 'report' THEN COALESCE(
                               (SELECT MIN(rd.approved_at) FROM documents rd WHERE rd.artefact_id = r.id),
                               CASE WHEN r.state = 'approved' THEN r.approved_at END)
                           WHEN 'odp' THEN {$odpDone}
                           WHEN 'board' THEN os.signed_at
                       END AS done_at
            ) d
            SQL);
    }
};
