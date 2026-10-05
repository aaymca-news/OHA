<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The report step (30 Sep 2026):
 *
 * - The report opens as soon as an OHA form has been uploaded and read (not refused).
 *   The form's approval no longer holds the staff back from the report.
 * - What was read from each uploaded report version is kept with it, so the report
 *   check runs against the current OHA form each time without reopening the file.
 *   The check's findings are derived, never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->jsonb('extracted')->nullable()->comment('Uploaded report versions: paragraphs and pictures read from the file, for the report check');
        });

        $this->artefactStatus(<<<'SQL'
            WHEN ar.state = 'not_started' AND ar.kind = 'report'
                 AND COALESCE(f.state, 'not_started') IN ('not_started', 'rules_failed') THEN 'locked'
            SQL);
    }

    public function down(): void
    {
        $this->artefactStatus(<<<'SQL'
            WHEN ar.state = 'not_started' AND ar.kind = 'report'
                 AND f.state IS DISTINCT FROM 'approved' THEN 'locked'
            SQL);

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('extracted');
        });
    }

    /** v_artefact_status with the given rule for when the report is locked; everything else as before. */
    private function artefactStatus(string $reportLock): void
    {
        DB::statement(<<<SQL
            CREATE OR REPLACE VIEW v_artefact_status AS
            SELECT ar.id AS artefact_id,
                   ar.assessment_id,
                   ar.kind,
                   ar.state,
                   CASE
                       {$reportLock}
                       WHEN ar.state = 'not_started' AND ar.kind = 'odp'
                            AND (f.state IS DISTINCT FROM 'approved' OR NOT COALESCE(rp.published, FALSE)) THEN 'locked'
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
                SELECT ar.state = 'approved'
                       OR EXISTS (SELECT 1 FROM documents d WHERE d.artefact_id = ar.id AND d.approved_at IS NOT NULL) AS published
            ) p
            LEFT JOIN LATERAL (
                SELECT r.state = 'approved'
                       OR EXISTS (SELECT 1 FROM documents d WHERE d.artefact_id = r.id AND d.approved_at IS NOT NULL) AS published
            ) rp ON TRUE
            SQL);
    }
};
