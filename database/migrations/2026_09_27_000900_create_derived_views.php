<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Derived values, calculated from stored facts every time they are read.
 * Every screen, report and export reads these views, so two screens can never
 * disagree. To change one, add a migration that runs CREATE OR REPLACE VIEW.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Points, percentage (one decimal) and band for every scored assessment.
        DB::statement(<<<'SQL'
            CREATE VIEW v_assessment_scores AS
            SELECT t.assessment_id,
                   t.movement_id,
                   t.assessed_on,
                   t.points_achieved,
                   t.points_available,
                   t.pct,
                   band.code AS band_code
            FROM (
                SELECT a.id AS assessment_id,
                       a.movement_id,
                       a.assessed_on,
                       SUM(cs.points) AS points_achieved,
                       SUM(cs.max_points_at_scoring) AS points_available,
                       ROUND(SUM(cs.points) * 100 / SUM(cs.max_points_at_scoring), 1) AS pct
                FROM assessments a
                JOIN category_scores cs ON cs.assessment_id = a.id
                GROUP BY a.id
            ) t
            CROSS JOIN LATERAL (
                SELECT hb.code FROM health_bands hb
                WHERE hb.min_pct <= t.pct
                ORDER BY hb.min_pct DESC
                LIMIT 1
            ) band
            SQL);

        // Each artefact's effective state (including the derived "locked"), whether it has been
        // released to the board, and whether the board has validated it by signing.
        DB::statement(<<<'SQL'
            CREATE VIEW v_artefact_status AS
            SELECT ar.id AS artefact_id,
                   ar.assessment_id,
                   ar.kind,
                   ar.state,
                   CASE
                       WHEN ar.state = 'not_started' AND ar.kind = 'report'
                            AND f.state IS DISTINCT FROM 'approved' THEN 'locked'
                       WHEN ar.state = 'not_started' AND ar.kind = 'odp'
                            AND (f.state IS DISTINCT FROM 'approved' OR r.state IS DISTINCT FROM 'approved') THEN 'locked'
                       ELSE ar.state
                   END AS effective_state,
                   (ar.released_to_board_at IS NOT NULL) AS released,
                   (bs.id IS NOT NULL) AS validated,
                   bs.signed_at AS validated_at
            FROM artefacts ar
            LEFT JOIN artefacts f ON f.assessment_id = ar.assessment_id AND f.kind = 'form'
            LEFT JOIN artefacts r ON r.assessment_id = ar.assessment_id AND r.kind = 'report'
            LEFT JOIN board_signatures bs ON bs.artefact_id = ar.id
            SQL);

        // Each movement's latest scored result, band, and when it is next due for assessment.
        // Assessed movements: cadence from the band. Unassessed: AAYMCA's planned date.
        DB::statement(<<<'SQL'
            CREATE VIEW v_movement_status AS
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
                   EXISTS (
                       SELECT 1 FROM artefacts o
                       JOIN assessments oa ON oa.id = o.assessment_id
                       JOIN board_signatures bs ON bs.artefact_id = o.id
                       WHERE oa.movement_id = m.id AND o.kind = 'odp'
                   ) AS has_odp
            FROM movements m
            LEFT JOIN LATERAL (
                SELECT vs.* FROM v_assessment_scores vs
                WHERE vs.movement_id = m.id
                ORDER BY vs.assessed_on DESC, vs.assessment_id DESC
                LIMIT 1
            ) s ON TRUE
            JOIN health_bands hb ON hb.code = COALESCE(s.band_code, 'notstarted')
            CROSS JOIN LATERAL (
                SELECT CASE
                           WHEN s.assessment_id IS NOT NULL
                               THEN (s.assessed_on + make_interval(months => hb.reassess_months))::date
                           ELSE m.planned_assessment_on
                       END AS next_assessment_on
            ) n
            SQL);

        // Every assessment's five gate milestones: when each is due (a date a person
        // set, otherwise opened + the gate's SLA), when it was actually cleared (the
        // artefact's own timestamp), and whether it was late or is overdue.
        DB::statement(<<<'SQL'
            CREATE VIEW v_assessment_milestones AS
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
                           WHEN 'report' THEN CASE WHEN r.state = 'approved' THEN r.approved_at END
                           WHEN 'odp' THEN CASE WHEN o.state = 'approved' THEN o.approved_at END
                           WHEN 'release' THEN o.released_to_board_at
                           WHEN 'board' THEN os.signed_at
                       END AS done_at
            ) d
            SQL);

        // One row per open assessment: who holds it, and when they must act.
        //   assessor  prepares the form, report or ODP (and anything sent back)
        //   approver  the OHA Lead or Assistant Lead it was routed to
        //   releaser  an approved ODP waits to be released to the board
        //   board     a released ODP waits for the Board Chairperson's signature
        // Due date = the sooner of the gate deadline and the holder's turnaround,
        // unless someone set the gate deadline by hand, in which case that wins.
        DB::statement(<<<'SQL'
            CREATE VIEW v_work_items AS
            WITH pivot AS (
                SELECT a.id AS assessment_id,
                       a.movement_id,
                       a.opened_at,
                       f.id AS form_id, f.state AS form_state, f.submitted_at AS form_submitted_at,
                       f.returned_at AS form_returned_at, f.routed_to AS form_routed_to,
                       r.id AS report_id, r.state AS report_state, r.submitted_at AS report_submitted_at,
                       r.returned_at AS report_returned_at, r.routed_to AS report_routed_to,
                       o.id AS odp_id, o.state AS odp_state, o.submitted_at AS odp_submitted_at,
                       o.returned_at AS odp_returned_at, o.routed_to AS odp_routed_to,
                       o.approved_at AS odp_approved_at, o.released_to_board_at AS odp_released_at,
                       (os.id IS NOT NULL) AS odp_signed
                FROM assessments a
                JOIN artefacts f ON f.assessment_id = a.id AND f.kind = 'form'
                JOIN artefacts r ON r.assessment_id = a.id AND r.kind = 'report'
                JOIN artefacts o ON o.assessment_id = a.id AND o.kind = 'odp'
                LEFT JOIN board_signatures os ON os.artefact_id = o.id
            ),
            holder AS (
                SELECT p.*,
                       CASE
                           WHEN p.form_state = 'pending_approval' OR p.report_state = 'pending_approval'
                                OR p.odp_state = 'pending_approval' THEN 'approver'
                           WHEN p.odp_state = 'approved' AND p.odp_released_at IS NULL THEN 'releaser'
                           WHEN p.odp_state = 'approved' AND NOT p.odp_signed THEN 'board'
                           WHEN p.odp_state = 'approved' THEN NULL
                           ELSE 'assessor'
                       END AS holder_role,
                       CASE
                           WHEN p.form_state = 'pending_approval' THEN 'form'
                           WHEN p.report_state = 'pending_approval' THEN 'report'
                           WHEN p.odp_state = 'pending_approval' THEN 'odp'
                           WHEN p.odp_state = 'approved' THEN 'odp'
                           WHEN p.form_state <> 'approved' THEN 'form'
                           WHEN p.report_state <> 'approved' THEN 'report'
                           ELSE 'odp'
                       END AS artefact_kind
                FROM pivot p
            ),
            clocked AS (
                SELECT h.*,
                       CASE h.holder_role
                           WHEN 'releaser' THEN 'release'
                           WHEN 'board' THEN 'board'
                           ELSE h.artefact_kind
                       END AS gate_code,
                       CASE
                           WHEN h.holder_role = 'approver' THEN
                               CASE h.artefact_kind WHEN 'form' THEN h.form_submitted_at
                                                    WHEN 'report' THEN h.report_submitted_at
                                                    ELSE h.odp_submitted_at END
                           WHEN h.holder_role = 'releaser' THEN COALESCE(h.odp_approved_at, h.opened_at)
                           WHEN h.holder_role = 'board' THEN COALESCE(h.odp_released_at, h.opened_at)
                           ELSE COALESCE(
                               CASE h.artefact_kind WHEN 'form' THEN h.form_returned_at
                                                    WHEN 'report' THEN h.report_returned_at
                                                    ELSE h.odp_returned_at END,
                               h.opened_at)
                       END AS holder_since,
                       CASE h.artefact_kind WHEN 'form' THEN h.form_id WHEN 'report' THEN h.report_id ELSE h.odp_id END AS artefact_id,
                       CASE WHEN h.holder_role = 'approver' THEN
                           CASE h.artefact_kind WHEN 'form' THEN h.form_routed_to
                                                WHEN 'report' THEN h.report_routed_to
                                                ELSE h.odp_routed_to END
                       END AS routed_to
                FROM holder h
                WHERE h.holder_role IS NOT NULL
            ),
            dated AS (
                SELECT c.*,
                       ms.due_on AS gate_due_on,
                       ms.set_by_hand AS gate_due_set_by_hand,
                       (c.holder_since::date + tr.days) AS turnaround_due_on
                FROM clocked c
                JOIN v_assessment_milestones ms ON ms.assessment_id = c.assessment_id AND ms.gate_code = c.gate_code
                JOIN turnaround_rules tr ON tr.holder_role = c.holder_role
            )
            SELECT d.assessment_id,
                   d.movement_id,
                   d.artefact_id,
                   d.artefact_kind,
                   d.gate_code,
                   d.holder_role,
                   d.routed_to,
                   d.holder_since,
                   d.gate_due_on,
                   d.turnaround_due_on,
                   d.gate_due_set_by_hand,
                   due.due_on,
                   (due.due_on - CURRENT_DATE) AS days_left,
                   (due.due_on < CURRENT_DATE) AS overdue
            FROM dated d
            CROSS JOIN LATERAL (
                SELECT CASE WHEN d.gate_due_set_by_hand THEN d.gate_due_on
                            ELSE LEAST(d.gate_due_on, d.turnaround_due_on) END AS due_on
            ) due
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_work_items');
        DB::statement('DROP VIEW IF EXISTS v_assessment_milestones');
        DB::statement('DROP VIEW IF EXISTS v_movement_status');
        DB::statement('DROP VIEW IF EXISTS v_artefact_status');
        DB::statement('DROP VIEW IF EXISTS v_assessment_scores');
    }
};
