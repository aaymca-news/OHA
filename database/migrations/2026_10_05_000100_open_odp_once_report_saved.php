<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ODP step (5 Oct 2026): the ODP opens as soon as a version of the report has
 * been saved. Neither the form's nor the report's approval holds the staff back;
 * the Administrators still approve each one.
 */
return new class extends Migration
{
    private const REPORT_LOCK = <<<'SQL'
        WHEN ar.state = 'not_started' AND ar.kind = 'report'
             AND COALESCE(f.state, 'not_started') IN ('not_started', 'rules_failed') THEN 'locked'
        SQL;

    public function up(): void
    {
        $this->artefactStatus(<<<'SQL'
            WHEN ar.state = 'not_started' AND ar.kind = 'odp'
                 AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.artefact_id = r.id) THEN 'locked'
            SQL);
    }

    public function down(): void
    {
        $this->artefactStatus(<<<'SQL'
            WHEN ar.state = 'not_started' AND ar.kind = 'odp'
                 AND (f.state IS DISTINCT FROM 'approved' OR NOT COALESCE(rp.published, FALSE)) THEN 'locked'
            SQL);
    }

    /** v_artefact_status with the given rule for when the ODP is locked; everything else as before. */
    private function artefactStatus(string $odpLock): void
    {
        $reportLock = self::REPORT_LOCK;

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
