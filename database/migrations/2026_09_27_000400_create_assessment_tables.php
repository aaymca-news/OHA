<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The assessment record. Each fact is stored once:
 *
 * - "Locked" is never stored: it follows from the upstream artefacts (see v_artefact_status).
 * - "Validated" is never stored: a report or ODP is validated when its board signature exists.
 * - A gate's completion date is never stored on the timeline: it is the artefact's own timestamp.
 * - Overall score, percentage and band are never stored: v_assessment_scores derives them.
 * - Points are frozen in category_scores at approval, with the maximum that applied then.
 * - Files are stored once, unchanged, on the private disk; tables hold their location and checksum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movement_id')->constrained()->restrictOnDelete();
            $table->string('period_label', 40)->comment('As written, e.g. "Feb 2026"');
            $table->date('assessed_on')->comment('First day of the assessment period');
            $table->timestampTz('opened_at');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['movement_id', 'assessed_on']);
        });

        // The three steps of an assessment. The OHA Lead (or Assistant Lead) APPROVES each
        // one, which is what lets the assessor go on. Releasing a document to the board, and
        // the Board Chairperson signing it, never hold the assessor back.
        Schema::create('artefacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('state', 30)->default('not_started');
            $table->text('author_context')->nullable()->comment('Report/ODP: context the assessor adds before generating');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('routed_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('returned_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->boolean('gap_ack')->default(false);
            $table->text('gap_ack_reason')->nullable();
            $table->foreignId('released_to_board_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_to_board_at')->nullable();
            $table->timestampsTz();

            $table->unique(['assessment_id', 'kind']);
        });
        DB::statement("ALTER TABLE artefacts ADD CONSTRAINT artefacts_kind_valid CHECK (kind IN ('form', 'report', 'odp'))");
        DB::statement(<<<'SQL'
            ALTER TABLE artefacts ADD CONSTRAINT artefacts_state_valid_for_kind CHECK (
                (kind = 'form' AND state IN ('not_started', 'rules_failed', 'ready', 'pending_approval', 'rejected', 'approved'))
             OR (kind IN ('report', 'odp') AND state IN ('not_started', 'drafted', 'pending_approval', 'rejected', 'approved'))
            )
            SQL);
        DB::statement("ALTER TABLE artefacts ADD CONSTRAINT artefacts_released_only_when_approved CHECK (released_to_board_at IS NULL OR state = 'approved')");
        DB::statement('ALTER TABLE artefacts ADD CONSTRAINT artefacts_no_self_approval CHECK (approved_by IS NULL OR submitted_by IS NULL OR approved_by <> submitted_by)');

        // The Board Chairperson's online signature on a released report or ODP: the drawn
        // signature (kept as evidence on the private disk), the typed name, and a fingerprint
        // of exactly what was signed. A signed document shows as "Validated".
        Schema::create('board_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artefact_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('signed_by')->constrained('users')->restrictOnDelete();
            $table->string('signed_name')->comment('Full name, as the Chairperson typed it');
            $table->string('signature_disk', 20);
            $table->string('signature_path');
            $table->char('signature_sha256', 64);
            $table->char('document_sha256', 64)->comment('Fingerprint of the document content at the moment of signing');
            $table->text('comment')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('signed_at')->useCurrent();
        });
        // A signature is the record of a moment: it is never edited.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION board_signatures_reject_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'board_signatures cannot be changed once made' USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER board_signatures_frozen
                BEFORE UPDATE ON board_signatures
                FOR EACH ROW EXECUTE PROCEDURE board_signatures_reject_update();
            SQL);

        Schema::create('artefact_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artefact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20)->default('comment');
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE artefact_comments ADD CONSTRAINT artefact_comments_kind_valid CHECK (kind IN ('comment', 'send_back', 'approval_note', 'board_note'))");

        Schema::create('form_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artefact_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('uploaded_at')->useCurrent();
            $table->jsonb('answers')->comment('Answers read from the form, keyed by question code. The record after upload.');
            $table->jsonb('form_meta')->comment('Also read from the form: notes beside answers, improvement comments, sign-off, cover facts, sheets present');
            $table->jsonb('printed_totals')->nullable()->comment('Totals the form itself printed; kept only for the cross-check');

            $table->index(['artefact_id', 'uploaded_at']);
        });

        Schema::create('category_scores', function (Blueprint $table) {
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->decimal('points', 6, 2);
            $table->unsignedSmallInteger('max_points_at_scoring');
            $table->foreignId('form_upload_id')->constrained()->restrictOnDelete();
            $table->timestampTz('scored_at')->useCurrent();

            $table->primary(['assessment_id', 'category_id']);
        });
        DB::statement('ALTER TABLE category_scores ADD CONSTRAINT category_scores_points_in_range CHECK (points >= 0 AND points <= max_points_at_scoring)');
        DB::statement('ALTER TABLE category_scores ADD CONSTRAINT category_scores_max_positive CHECK (max_points_at_scoring > 0)');
        // Frozen at approval: a score is never edited. A correction is a new assessment.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION category_scores_reject_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'category_scores are frozen once recorded: UPDATE is not allowed'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER category_scores_frozen
                BEFORE UPDATE ON category_scores
                FOR EACH ROW EXECUTE PROCEDURE category_scores_reject_update();
            SQL);

        Schema::create('form_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_upload_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 10);
            $table->string('rule', 40)->comment('The check that produced this finding, e.g. unanswered');
            $table->string('ref', 60)->nullable()->comment('Stable reference for a gap, e.g. q:Q246 or c:financial');
            $table->string('question_code', 10)->nullable();
            $table->foreignId('category_id')->nullable()->comment('NULL = General Information')->constrained()->restrictOnDelete();
            $table->string('location')->nullable()->comment('Where on the form, e.g. "2 Financial Stability · Q246"');
            $table->text('message');
            $table->text('hint')->nullable();
            $table->unsignedSmallInteger('points_at_stake')->default(0);
            $table->string('dqa_dimension', 20);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolved_note')->nullable();
        });
        DB::statement("ALTER TABLE form_findings ADD CONSTRAINT form_findings_severity_valid CHECK (severity IN ('error', 'missing', 'warning'))");
        DB::statement("ALTER TABLE form_findings ADD CONSTRAINT form_findings_dqa_valid CHECK (dqa_dimension IN ('completeness', 'accuracy', 'consistency', 'timeliness', 'validity', 'traceability'))");
        DB::statement('ALTER TABLE form_findings ADD CONSTRAINT form_findings_resolution_complete CHECK ((resolved_at IS NULL) = (resolved_by IS NULL) AND (resolved_at IS NULL OR resolved_note IS NOT NULL))');

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

        // Downloadable report and ODP files: generated by the system, or supplied for reference.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artefact_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20)->comment('generated: produced by the system. reference: supplied alongside, for information.');
            $table->string('format', 10);
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['artefact_id', 'sha256']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('generated', 'reference'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_format_valid CHECK (format IN ('docx', 'pdf', 'xlsx'))");

        Schema::create('timeline_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gate_id')->nullable()->comment('Set: a hand-set gate deadline. NULL: a custom step.')->constrained()->restrictOnDelete();
            $table->string('label')->nullable();
            $table->date('due_on')->nullable();
            $table->date('done_on')->nullable()->comment('Custom steps only; a gate is done when its artefact says so');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE timeline_items ADD CONSTRAINT timeline_items_shape CHECK ((gate_id IS NULL AND label IS NOT NULL) OR (gate_id IS NOT NULL AND done_on IS NULL AND due_on IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX timeline_items_one_per_gate ON timeline_items (assessment_id, gate_id) WHERE gate_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_items');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_sections');
        Schema::dropIfExists('form_findings');
        Schema::dropIfExists('category_scores');
        Schema::dropIfExists('form_uploads');
        Schema::dropIfExists('artefact_comments');
        Schema::dropIfExists('board_signatures');
        Schema::dropIfExists('artefacts');
        Schema::dropIfExists('assessments');
        DB::unprepared('DROP FUNCTION IF EXISTS category_scores_reject_update()');
        DB::unprepared('DROP FUNCTION IF EXISTS board_signatures_reject_update()');
    }
};
