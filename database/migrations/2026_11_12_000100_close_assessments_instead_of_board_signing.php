<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Board Chairperson's signature becomes optional (12 Nov 2026). Stage 1 now ends when
 * the assessment is CLOSED (by its assessors, an Administrator or the Super Administrator)
 * once its ODP is approved: closing freezes the OHA form, the report and the ODP, starts
 * the follow-up clock and sets the movement's first baseline. Signing still validates the
 * ODP ("Validated by the board"), at any time after its approval, but holds nothing up.
 *
 *   - assessments.closed_at / closed_by: when and by whom; the assessments already signed
 *     are taken as closed when they were signed, so their follow-up dates do not move;
 *   - the fourth gate, "board", becomes "close" (Assessment closed);
 *   - the holder "board" in v_work_items becomes "closer": an approved ODP waits to be closed;
 *   - v_movement_status.has_odp: the movement has a closed assessment (its ODP is the plan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->timestampTz('closed_at')->nullable()->comment('Stage 1 closed: the form, report and ODP frozen, Baseline 1 set, the follow-up clock started');
            $table->foreignId('closed_by')->nullable()->comment('Who closed it')->constrained('users')->nullOnDelete();
        });
        DB::statement(<<<'SQL'
            UPDATE assessments a
            SET closed_at = bs.signed_at, closed_by = bs.signed_by
            FROM artefacts o
            JOIN board_signatures bs ON bs.artefact_id = o.id
            WHERE o.assessment_id = a.id AND o.kind = 'odp'
            SQL);

        DB::table('gates')->where('code', 'board')->update(['code' => 'close', 'name' => 'Closing', 'milestone_label' => 'Assessment closed']);
        DB::statement('ALTER TABLE turnaround_rules DROP CONSTRAINT turnaround_rules_holder_role');
        DB::table('turnaround_rules')->where('holder_role', 'board')->update(['holder_role' => 'closer', 'days' => 7]);
        DB::statement("ALTER TABLE turnaround_rules ADD CONSTRAINT turnaround_rules_holder_role CHECK (holder_role IN ('assessor', 'approver', 'closer'))");

        $this->views(closed: 'a.closed_at', closedFlag: '(a.closed_at IS NOT NULL)', joinSignature: '', holder: 'closer', gate: 'close',
            hasOdp: 'SELECT 1 FROM assessments oa WHERE oa.movement_id = m.id AND oa.closed_at IS NOT NULL');
    }

    public function down(): void
    {
        $this->views(closed: 'os.signed_at', closedFlag: '(os.id IS NOT NULL)', joinSignature: 'LEFT JOIN board_signatures os ON os.artefact_id = o.id', holder: 'board', gate: 'board',
            hasOdp: "SELECT 1 FROM artefacts o JOIN assessments oa ON oa.id = o.assessment_id JOIN board_signatures bs ON bs.artefact_id = o.id WHERE oa.movement_id = m.id AND o.kind = 'odp'",
            restore: function (): void {
                DB::statement('ALTER TABLE turnaround_rules DROP CONSTRAINT turnaround_rules_holder_role');
                DB::table('turnaround_rules')->where('holder_role', 'closer')->update(['holder_role' => 'board', 'days' => 21]);
                DB::statement("ALTER TABLE turnaround_rules ADD CONSTRAINT turnaround_rules_holder_role CHECK (holder_role IN ('assessor', 'approver', 'board'))");
                DB::table('gates')->where('code', 'close')->update(['code' => 'board', 'name' => 'Board signature', 'milestone_label' => 'ODP signed by the Board Chairperson']);
            });

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });
    }

    /**
     * v_assessment_milestones, v_work_items and v_movement_status, with the fourth gate and
     * the end of Stage 1 as given; everything else as before (2026_09_30_000100, 2026_10_08_000200).
     */
    private function views(string $closed, string $closedFlag, string $joinSignature, string $holder, string $gate, string $hasOdp, ?Closure $restore = null): void
    {
        // The views read the gate and holder codes: drop them, change the codes, create them again.
        DB::statement('DROP VIEW IF EXISTS v_work_items');
        DB::statement('DROP VIEW IF EXISTS v_assessment_milestones');
        DB::statement('DROP VIEW IF EXISTS v_movement_status');
        if ($restore !== null) {
            $restore();
        }

        DB::statement(<<<SQL
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
            {$joinSignature}
            LEFT JOIN timeline_items ti ON ti.assessment_id = a.id AND ti.gate_id = g.id
            CROSS JOIN LATERAL (
                SELECT CASE g.code
                           WHEN 'form' THEN COALESCE((SELECT MIN(fu.approved_at) FROM form_uploads fu WHERE fu.artefact_id = f.id),
                                                     CASE WHEN f.state = 'approved' THEN f.approved_at END)
                           WHEN 'report' THEN COALESCE(
                               (SELECT MIN(rd.approved_at) FROM documents rd WHERE rd.artefact_id = r.id),
                               CASE WHEN r.state = 'approved' THEN r.approved_at END)
                           WHEN 'odp' THEN COALESCE((SELECT MIN(od.approved_at) FROM documents od WHERE od.artefact_id = o.id),
                                                    CASE WHEN o.state = 'approved' THEN o.approved_at END)
                           WHEN '{$gate}' THEN {$closed}
                       END AS done_at
            ) d
            SQL);

        // One row per open assessment: who holds it, and when they must act.
        //   assessor  prepares the form, report or ODP (and anything sent back)
        //   approver  the Administrators: something is waiting for approval
        //   closer    the ODP is approved: the assessors (or an Administrator) close the assessment
        DB::statement(<<<SQL
            CREATE VIEW v_work_items AS
            WITH pivot AS (
                SELECT a.id AS assessment_id,
                       a.movement_id,
                       a.opened_at,
                       f.id AS form_id, f.state AS form_state, f.submitted_at AS form_submitted_at,
                       f.returned_at AS form_returned_at,
                       r.id AS report_id, r.state AS report_state, r.submitted_at AS report_submitted_at,
                       r.returned_at AS report_returned_at, rs.published AS report_published,
                       o.id AS odp_id, o.state AS odp_state, o.submitted_at AS odp_submitted_at,
                       o.returned_at AS odp_returned_at, o.approved_at AS odp_approved_at,
                       {$closedFlag} AS closed
                FROM assessments a
                JOIN artefacts f ON f.assessment_id = a.id AND f.kind = 'form'
                JOIN artefacts r ON r.assessment_id = a.id AND r.kind = 'report'
                JOIN v_artefact_status rs ON rs.artefact_id = r.id
                JOIN artefacts o ON o.assessment_id = a.id AND o.kind = 'odp'
                {$joinSignature}
            ),
            holder AS (
                SELECT p.*,
                       CASE
                           WHEN p.closed THEN NULL
                           WHEN p.form_state = 'pending_approval' OR p.report_state = 'pending_approval'
                                OR p.odp_state = 'pending_approval' THEN 'approver'
                           WHEN p.odp_state = 'approved' THEN '{$holder}'
                           ELSE 'assessor'
                       END AS holder_role,
                       CASE
                           WHEN p.form_state = 'pending_approval' THEN 'form'
                           WHEN p.report_state = 'pending_approval' THEN 'report'
                           WHEN p.odp_state = 'pending_approval' THEN 'odp'
                           WHEN p.odp_state = 'approved' THEN 'odp'
                           WHEN p.form_state <> 'approved' THEN 'form'
                           WHEN NOT p.report_published THEN 'report'
                           ELSE 'odp'
                       END AS artefact_kind
                FROM pivot p
            ),
            clocked AS (
                SELECT h.*,
                       CASE WHEN h.holder_role = '{$holder}' THEN '{$gate}' ELSE h.artefact_kind END AS gate_code,
                       CASE
                           WHEN h.holder_role = 'approver' THEN
                               CASE h.artefact_kind WHEN 'form' THEN h.form_submitted_at
                                                    WHEN 'report' THEN h.report_submitted_at
                                                    ELSE h.odp_submitted_at END
                           WHEN h.holder_role = '{$holder}' THEN COALESCE(h.odp_approved_at, h.opened_at)
                           ELSE COALESCE(
                               CASE h.artefact_kind WHEN 'form' THEN h.form_returned_at
                                                    WHEN 'report' THEN h.report_returned_at
                                                    ELSE h.odp_returned_at END,
                               h.opened_at)
                       END AS holder_since,
                       CASE h.artefact_kind WHEN 'form' THEN h.form_id WHEN 'report' THEN h.report_id ELSE h.odp_id END AS artefact_id
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

        // Each movement's latest scored result, band, and when it is next due.
        DB::statement(<<<SQL
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
                   EXISTS ({$hasOdp}) AS has_odp
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
    }
};
