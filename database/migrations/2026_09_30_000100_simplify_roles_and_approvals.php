<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The roles and approvals as simplified on 30 Sep 2026.
 *
 * - AAYMCA has three roles: Staff, Administrator and Super Administrator. The
 *   Administrators (either kind) approve the OHA form, the report and the ODP;
 *   nobody approves their own work. Only a Super Administrator gives or takes away
 *   an Administrator role. The O.H.A Leads become Administrators, the General
 *   Secretary becomes Staff, and the existing Administrator becomes the Super
 *   Administrator.
 * - A National Movement has one member: its Board Chairperson, who signs the ODP.
 *   The other board members are removed (deactivated, if the audit trail refers to them).
 * - Nothing is "released to the board" any more: an approved report or ODP is
 *   visible to the Chairperson at once. Only the ODP is signed.
 * - The report is written outside the system and uploaded, in versions; the
 *   system no longer generates it. A version is approved on its own, so the last
 *   approved version stays visible while a newer one waits for approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropViews();

        // --- Roles -----------------------------------------------------------------
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_valid');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_chair_is_board');
        DB::statement('DROP INDEX users_one_oha_lead');
        DB::statement('DROP INDEX users_one_oha_asst_lead');
        DB::statement('DROP INDEX users_one_chair_per_movement');

        DB::table('users')->where('role', 'admin')->update(['role' => 'super_admin']);
        DB::table('users')->whereIn('role', ['oha_lead', 'oha_asst_lead'])->update(['role' => 'admin']);
        DB::table('users')->where('role', 'gs')->update(['role' => 'staff']);
        $this->removeBoardMembersOtherThanTheChair();

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_chair');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_valid CHECK (role IN ('super_admin', 'admin', 'staff', 'board'))");
        DB::statement("CREATE UNIQUE INDEX users_one_chair_per_movement ON users (movement_id) WHERE role = 'board' AND active");

        // --- No release to the board, no routing to one approver ------------------------
        DB::statement('ALTER TABLE artefacts DROP CONSTRAINT artefacts_released_only_when_approved');
        Schema::table('artefacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_to_board_by');
            $table->dropColumn('released_to_board_at');
            $table->dropConstrainedForeignId('routed_to');
        });

        $release = DB::table('gates')->where('code', 'release')->value('id');
        if ($release !== null) {
            DB::table('timeline_items')->where('gate_id', $release)->delete();
            DB::table('gates')->where('id', $release)->delete();
        }
        DB::table('gates')->where('code', 'board')->update(['sort_order' => 4, 'name' => 'Board signature', 'milestone_label' => 'ODP signed by the Board Chairperson']);

        DB::statement('ALTER TABLE turnaround_rules DROP CONSTRAINT turnaround_rules_holder_role');
        DB::table('turnaround_rules')->where('holder_role', 'releaser')->delete();
        DB::statement("ALTER TABLE turnaround_rules ADD CONSTRAINT turnaround_rules_holder_role CHECK (holder_role IN ('assessor', 'approver', 'board'))");

        // --- The report is uploaded, in versions ----------------------------------------
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        Schema::table('documents', function (Blueprint $table) {
            $table->text('note')->nullable()->comment('Uploaded report versions: what changed, in the uploader’s words');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable()->comment('Uploaded report versions: when an Administrator approved this version');
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('generated', 'reference', 'uploaded'))");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_approval_complete CHECK ((approved_at IS NULL) = (approved_by IS NULL))');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_only_uploads_approved CHECK (approved_at IS NULL OR purpose = 'uploaded')");

        $this->resetGeneratedReports();

        $this->createViews();
    }

    public function down(): void
    {
        $this->dropViews();

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_only_uploads_approved');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_approval_complete');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        DB::table('documents')->where('purpose', 'uploaded')->update(['purpose' => 'reference']);
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'note']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('generated', 'reference'))");

        DB::statement('ALTER TABLE turnaround_rules DROP CONSTRAINT turnaround_rules_holder_role');
        DB::table('turnaround_rules')->insert(['holder_role' => 'releaser', 'days' => 7]);
        DB::statement("ALTER TABLE turnaround_rules ADD CONSTRAINT turnaround_rules_holder_role CHECK (holder_role IN ('assessor', 'approver', 'releaser', 'board'))");

        DB::table('gates')->where('code', 'board')->update(['sort_order' => 5, 'name' => 'Board validation', 'milestone_label' => 'ODP validated by the board']);
        DB::table('gates')->insert(['code' => 'release', 'name' => 'Release to the board', 'milestone_label' => 'ODP released to the board', 'sort_order' => 4, 'sla_days' => 77]);

        Schema::table('artefacts', function (Blueprint $table) {
            $table->foreignId('routed_to')->nullable()->after('submitted_at')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_to_board_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_to_board_at')->nullable();
        });
        DB::statement("ALTER TABLE artefacts ADD CONSTRAINT artefacts_released_only_when_approved CHECK (released_to_board_at IS NULL OR state = 'approved')");

        DB::statement('DROP INDEX users_one_chair_per_movement');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_valid');
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_chair')->default(false)->after('movement_id');
        });
        DB::table('users')->where('role', 'board')->update(['is_chair' => true]);
        // Only one of each O.H.A Lead role could be held, so every Administrator goes back to Staff.
        DB::table('users')->where('role', 'admin')->update(['role' => 'staff']);
        DB::table('users')->where('role', 'super_admin')->update(['role' => 'admin']);
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_valid CHECK (role IN ('admin', 'staff', 'oha_lead', 'oha_asst_lead', 'gs', 'board'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_chair_is_board CHECK (NOT is_chair OR role = 'board')");
        DB::statement("CREATE UNIQUE INDEX users_one_oha_lead ON users (role) WHERE role = 'oha_lead' AND active");
        DB::statement("CREATE UNIQUE INDEX users_one_oha_asst_lead ON users (role) WHERE role = 'oha_asst_lead' AND active");
        DB::statement('CREATE UNIQUE INDEX users_one_chair_per_movement ON users (movement_id) WHERE is_chair AND active');

        (require __DIR__.'/2026_09_27_000900_create_derived_views.php')->up();
    }

    /**
     * Board members who are not the Chairperson are deleted; one the audit trail (or
     * anything else) refers to is deactivated instead, since accounts it names are kept.
     */
    private function removeBoardMembersOtherThanTheChair(): void
    {
        $members = DB::table('users')->where('role', 'board')->where('is_chair', false)->pluck('id');

        foreach ($members as $id) {
            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table('notifications')->where('notifiable_type', 'App\\Models\\User')->where('notifiable_id', $id)->delete();

            try {
                DB::transaction(fn () => DB::table('users')->where('id', $id)->delete());
            } catch (QueryException) {
                DB::table('users')->where('id', $id)->update(['active' => false]);
            }
        }
    }

    /**
     * A report the system generated has no file behind it. It goes back to "not
     * started" so the real report can be uploaded; the audit trail records why.
     */
    private function resetGeneratedReports(): void
    {
        $reports = DB::table('artefacts')->where('kind', 'report')->where('state', '!=', 'not_started')
            ->whereNotExists(fn ($q) => $q->from('documents')->whereColumn('documents.artefact_id', 'artefacts.id')->where('purpose', 'uploaded'))
            ->get(['id', 'assessment_id', 'state']);

        foreach ($reports as $report) {
            DB::table('document_sections')->where('artefact_id', $report->id)->delete();
            DB::table('artefacts')->where('id', $report->id)->update([
                'state' => 'not_started', 'submitted_by' => null, 'submitted_at' => null, 'returned_at' => null,
                'approved_by' => null, 'approved_at' => null, 'author_context' => null, 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'occurred_at' => now(),
                'actor_id' => null,
                'action' => 'report.reset_for_upload',
                'subject_type' => 'App\\Models\\Artefact',
                'subject_id' => $report->id,
                'assessment_id' => $report->assessment_id,
                'from_state' => $report->state,
                'to_state' => 'not_started',
                'payload' => json_encode(['reason' => 'Reports are now uploaded, not generated. Upload the report to continue.']),
            ]);
        }
    }

    private function dropViews(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_work_items');
        DB::statement('DROP VIEW IF EXISTS v_assessment_milestones');
        DB::statement('DROP VIEW IF EXISTS v_movement_status');
        DB::statement('DROP VIEW IF EXISTS v_artefact_status');
        DB::statement('DROP VIEW IF EXISTS v_assessment_scores');
    }

    private function createViews(): void
    {
        // Points, percentage (one decimal) and band for every scored assessment. Unchanged.
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

        // Each artefact's effective state (including the derived "locked"), whether it is
        // published, and whether the ODP is validated by the Chairperson's signature.
        //   published  approved at least once, so every AAYMCA staff member sees it (and the
        //              Board Chairperson, for the report and ODP). A report stays published
        //              while a newer version waits for approval: its last approved version shows.
        //   validated  the ODP only: the Board Chairperson has signed it.
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

        // Each movement's latest scored result, band, and when it is next due. Unchanged.
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

        // Every assessment's four gate milestones: when each is due (a date a person set,
        // otherwise opened + the gate's SLA), when it was cleared, and whether it was late
        // or is overdue. The report gate is cleared by its first approved version.
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
                           WHEN 'report' THEN COALESCE(
                               (SELECT MIN(rd.approved_at) FROM documents rd WHERE rd.artefact_id = r.id),
                               CASE WHEN r.state = 'approved' THEN r.approved_at END)
                           WHEN 'odp' THEN CASE WHEN o.state = 'approved' THEN o.approved_at END
                           WHEN 'board' THEN os.signed_at
                       END AS done_at
            ) d
            SQL);

        // One row per open assessment: who holds it, and when they must act.
        //   assessor  prepares the form, report or ODP (and anything sent back)
        //   approver  the Administrators: something is waiting for approval
        //   board     an approved ODP waits for the Board Chairperson's signature
        // Due date = the sooner of the gate deadline and the holder's turnaround,
        // unless someone set the gate deadline by hand, in which case that wins.
        DB::statement(<<<'SQL'
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
                       (os.id IS NOT NULL) AS odp_signed
                FROM assessments a
                JOIN artefacts f ON f.assessment_id = a.id AND f.kind = 'form'
                JOIN artefacts r ON r.assessment_id = a.id AND r.kind = 'report'
                JOIN v_artefact_status rs ON rs.artefact_id = r.id
                JOIN artefacts o ON o.assessment_id = a.id AND o.kind = 'odp'
                LEFT JOIN board_signatures os ON os.artefact_id = o.id
            ),
            holder AS (
                SELECT p.*,
                       CASE
                           WHEN p.form_state = 'pending_approval' OR p.report_state = 'pending_approval'
                                OR p.odp_state = 'pending_approval' THEN 'approver'
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
                           WHEN NOT p.report_published THEN 'report'
                           ELSE 'odp'
                       END AS artefact_kind
                FROM pivot p
            ),
            clocked AS (
                SELECT h.*,
                       CASE WHEN h.holder_role = 'board' THEN 'board' ELSE h.artefact_kind END AS gate_code,
                       CASE
                           WHEN h.holder_role = 'approver' THEN
                               CASE h.artefact_kind WHEN 'form' THEN h.form_submitted_at
                                                    WHEN 'report' THEN h.report_submitted_at
                                                    ELSE h.odp_submitted_at END
                           WHEN h.holder_role = 'board' THEN COALESCE(h.odp_approved_at, h.opened_at)
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
    }
};
