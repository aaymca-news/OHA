<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No band-based next assessment date (decided 12 Nov 2026): a movement's next assessment
 * is started by its assessors, an Administrator or the Super Administrator once its
 * previous cycle is closed, whenever they are ready. So v_movement_status.next_assessment_on
 * is only the first assessment an Administrator planned for a movement never assessed
 * (movements.planned_assessment_on); reassessment_overdue, that planned date passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->view(<<<'SQL'
            CASE WHEN NOT EXISTS (SELECT 1 FROM assessments x WHERE x.movement_id = m.id)
                 THEN m.planned_assessment_on
            END
            SQL);
    }

    public function down(): void
    {
        $this->view(<<<'SQL'
            CASE
                WHEN s.assessment_id IS NOT NULL
                    THEN (s.assessed_on + make_interval(months => hb.reassess_months))::date
                ELSE m.planned_assessment_on
            END
            SQL);
    }

    private function view(string $next): void
    {
        DB::statement(<<<SQL
            CREATE OR REPLACE VIEW v_movement_status AS
            SELECT m.id AS movement_id,
                   m.slug,
                   m.name,
                   m.zone_id,
                   m.membership_status_id,
                   s.assessment_id AS latest_assessment_id,
                   s.assessed_on AS last_assessed_on,
                   s.points_achieved,
                   s.points_available,
                   s.pct,
                   hb.code AS band_code,
                   hb.reassess_months,
                   n.next_assessment_on,
                   (n.next_assessment_on IS NOT NULL AND n.next_assessment_on < CURRENT_DATE) AS reassessment_overdue,
                   EXISTS (SELECT 1 FROM assessments oa WHERE oa.movement_id = m.id AND oa.closed_at IS NOT NULL) AS has_odp
            FROM movements m
            LEFT JOIN LATERAL (
                SELECT vs.* FROM v_assessment_scores vs
                WHERE vs.movement_id = m.id
                ORDER BY vs.assessed_on DESC, vs.assessment_id DESC
                LIMIT 1
            ) s ON TRUE
            JOIN health_bands hb ON hb.code = COALESCE(s.band_code, 'notstarted')
            CROSS JOIN LATERAL (
                SELECT {$next} AS next_assessment_on
            ) n
            SQL);
    }
};
