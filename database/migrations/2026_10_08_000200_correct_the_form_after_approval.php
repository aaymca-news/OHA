<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correcting the OHA form (8 Oct 2026):
 *
 * - What the form is missing can be typed in the platform, in the form the question asks
 *   for. The file is never changed: the typed answers are kept with the upload, with who
 *   typed them and when, and the form is checked and scored again with them.
 * - Like the report and the ODP, the form can be corrected after approval (typed answers
 *   or a new upload) until the Board Chairperson signs the ODP. It then goes back to the
 *   Administrators; until they approve it again, everyone keeps seeing the approved form
 *   and its recorded score. So each upload records whether it was approved.
 */
return new class extends Migration
{
    private const REPORT_LOCK = <<<'SQL'
        WHEN ar.state = 'not_started' AND ar.kind = 'report'
             AND COALESCE(f.state, 'not_started') IN ('not_started', 'rules_failed') THEN 'locked'
        SQL;

    private const ODP_LOCK = <<<'SQL'
        WHEN ar.state = 'not_started' AND ar.kind = 'odp'
             AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.artefact_id = r.id) THEN 'locked'
        SQL;

    public function up(): void
    {
        Schema::table('form_uploads', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable()->comment('When an Administrator approved the form as read from this upload');
            $table->jsonb('supplied')->nullable()->comment('Answers typed in the platform for what the file lacks, by finding ref, with who typed them and when');
        });

        // The upload each recorded score came from was the one approved.
        DB::statement(<<<'SQL'
            UPDATE form_uploads fu
            SET approved_at = COALESCE(a.approved_at, s.scored_at),
                approved_by = a.approved_by
            FROM (SELECT form_upload_id, MIN(scored_at) AS scored_at FROM category_scores GROUP BY form_upload_id) s,
                 artefacts a
            WHERE s.form_upload_id = fu.id AND a.id = fu.artefact_id
            SQL);

        $this->views(
            published: <<<'SQL'
                SELECT ar.state = 'approved'
                       OR EXISTS (SELECT 1 FROM documents d WHERE d.artefact_id = ar.id AND d.approved_at IS NOT NULL)
                       OR EXISTS (SELECT 1 FROM form_uploads fu WHERE fu.artefact_id = ar.id AND fu.approved_at IS NOT NULL) AS published
                SQL,
            formDone: <<<'SQL'
                COALESCE((SELECT MIN(fu.approved_at) FROM form_uploads fu WHERE fu.artefact_id = f.id),
                         CASE WHEN f.state = 'approved' THEN f.approved_at END)
                SQL,
        );
    }

    public function down(): void
    {
        $this->views(
            published: <<<'SQL'
                SELECT ar.state = 'approved'
                       OR EXISTS (SELECT 1 FROM documents d WHERE d.artefact_id = ar.id AND d.approved_at IS NOT NULL) AS published
                SQL,
            formDone: "CASE WHEN f.state = 'approved' THEN f.approved_at END",
        );

        Schema::table('form_uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'supplied']);
        });
    }

    /** v_artefact_status and v_assessment_milestones with the given rules; everything else as before. */
    private function views(string $published, string $formDone): void
    {
        $reportLock = self::REPORT_LOCK;
        $odpLock = self::ODP_LOCK;

        DB::statement(<<<SQL
            CREATE OR REPLACE VIEW v_artefact_status AS
            SELECT ar.id AS artefact_id,
                   ar.assessment_id,
                   ar.kind,
                   ar.state,
                   CASE
                       {$reportLock}
                       {$odpLock}
                       ELSE ar.state
                   END AS effective_state,
                   COALESCE(p.published, FALSE) AS published,
                   (ar.kind = 'odp' AND bs.id IS NOT NULL) AS validated,
                   CASE WHEN ar.kind = 'odp' THEN bs.signed_at END AS validated_at
            FROM artefacts ar
            LEFT JOIN artefacts f ON f.assessment_id = ar.assessment_id AND f.kind = 'form'
            LEFT JOIN artefacts r ON r.assessment_id = ar.assessment_id AND r.kind = 'report'
            LEFT JOIN board_signatures bs ON bs.artefact_id = ar.id
            CROSS JOIN LATERAL (
                {$published}
            ) p
            LEFT JOIN LATERAL (
                SELECT r.state = 'approved'
                       OR EXISTS (SELECT 1 FROM documents d WHERE d.artefact_id = r.id AND d.approved_at IS NOT NULL) AS published
            ) rp ON TRUE
            SQL);

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
                           WHEN 'form' THEN {$formDone}
                           WHEN 'report' THEN COALESCE(
                               (SELECT MIN(rd.approved_at) FROM documents rd WHERE rd.artefact_id = r.id),
                               CASE WHEN r.state = 'approved' THEN r.approved_at END)
                           WHEN 'odp' THEN COALESCE((SELECT MIN(od.approved_at) FROM documents od WHERE od.artefact_id = o.id),
                                                    CASE WHEN o.state = 'approved' THEN o.approved_at END)
                           WHEN 'board' THEN os.signed_at
                       END AS done_at
            ) d
            SQL);
    }
};
