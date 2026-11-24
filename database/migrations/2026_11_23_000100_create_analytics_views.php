<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The analytics layer (decided 23 Nov 2026): two flat views over the baselines in force, one
 * row per movement and score point, so the dashboards (and any reporting tool later) read
 * the scores the same way. Superseded baselines (set aside when a follow-up was reopened)
 * are left out, as everywhere else.
 *
 *   v_analytics_scores       one row per baseline: the movement, its zone, the score, its
 *                            band, the change since Baseline 1, the ODP tasks done and the
 *                            evidence at that point, and whether it is the current score
 *   v_analytics_area_scores  one row per baseline and scored area: points, maximum, percentage
 *
 * Both read v_baseline_scores: a migration that redefines it must drop these first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW v_analytics_scores AS
            SELECT a.movement_id,
                   m.zone_id,
                   b.assessment_id,
                   a.period_label,
                   b.id AS baseline_id,
                   b.number,
                   b.follow_up_number,
                   b.set_at,
                   s.points_achieved,
                   s.points_available,
                   s.pct,
                   s.band_code,
                   s1.pct AS baseline_pct,
                   ROUND(s.pct - s1.pct, 1) AS change_since_baseline,
                   b.tasks_done,
                   b.tasks_total,
                   b.evidence_count,
                   jsonb_array_length(COALESCE(b.evidence_gaps, '[]'::jsonb)) AS evidence_gaps,
                   b.number = (SELECT MAX(b2.number) FROM baselines b2 WHERE b2.assessment_id = b.assessment_id AND b2.superseded_at IS NULL) AS is_current,
                   a.follow_up_closed_at IS NOT NULL AS cycle_finished
            FROM baselines b
            JOIN v_baseline_scores s ON s.baseline_id = b.id
            JOIN assessments a ON a.id = b.assessment_id
            JOIN movements m ON m.id = a.movement_id
            LEFT JOIN baselines b1 ON b1.assessment_id = b.assessment_id AND b1.number = 1 AND b1.superseded_at IS NULL
            LEFT JOIN v_baseline_scores s1 ON s1.baseline_id = b1.id
            WHERE b.superseded_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW v_analytics_area_scores AS
            SELECT a.movement_id,
                   m.zone_id,
                   b.assessment_id,
                   b.id AS baseline_id,
                   b.number,
                   c.code AS category_code,
                   bs.points,
                   bs.max_points_at_scoring,
                   ROUND(bs.points * 100 / bs.max_points_at_scoring, 1) AS pct
            FROM baselines b
            JOIN baseline_scores bs ON bs.baseline_id = b.id
            JOIN categories c ON c.id = bs.category_id
            JOIN assessments a ON a.id = b.assessment_id
            JOIN movements m ON m.id = a.movement_id
            WHERE b.superseded_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_analytics_area_scores');
        DB::statement('DROP VIEW IF EXISTS v_analytics_scores');
    }
};
