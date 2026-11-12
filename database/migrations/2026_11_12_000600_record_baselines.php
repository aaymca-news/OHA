<?php

use App\Demo\DemoChanges;
use App\Oha\Answer;
use App\Oha\EvidenceQuestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baselines 1–4 (decided 12 Nov 2026). Baseline 1 is the approved form, recorded when the
 * assessment is closed; each follow-up closed records the next (follow-up 1: Baseline 2,
 * and so on). During an open follow-up, the answers are re-scored, from a re-uploaded Excel
 * form or answer by answer on the site, with the form's own scoring (Not Applicable scores
 * 0, the maximum unchanged, exactly as the Excel form). Closing the follow-up is the
 * sign-off: its baseline is then frozen.
 *
 *   baselines        one per baseline: the answers it scored, what changed since the one
 *                    before (with the reasons), the "Yes" answers still without evidence,
 *                    and the ODP's progress then. Never edited: when an Administrator
 *                    reopens a follow-up, its baseline is set aside (superseded, kept) and
 *                    a new one is recorded when it is closed again.
 *   baseline_scores  its points per weighted category, with the maximum then. Never edited.
 *   follow_up_windows.answers / answer_changes
 *                    the answers being re-scored while the follow-up is open.
 *   rescore_uploads  the Excel forms re-uploaded during a follow-up.
 *
 * The newest baseline is the movement's score everywhere: v_current_category_scores
 * (falling back to the approved form's category_scores before the assessment is closed)
 * feeds v_assessment_scores, which gains the column `baseline`. Percentages and bands are
 * derived, never stored, as before (v_baseline_scores for each baseline).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->comment('1: the closed assessment; 2-4: after follow-ups 1-3');
            $table->unsignedSmallInteger('follow_up_number')->nullable()->comment('The follow-up whose closing recorded it; NULL for Baseline 1');
            $table->jsonb('answers')->comment('Every answer it was scored from, by question code');
            $table->jsonb('changes')->nullable()->comment('Answers changed since the baseline before: code => from, to, reason, by, at, via');
            $table->jsonb('evidence_gaps')->nullable()->comment('Question codes answered "Yes" with no evidence counted yet; NULL: not recorded');
            $table->unsignedSmallInteger('tasks_done')->nullable();
            $table->unsignedSmallInteger('tasks_total')->nullable();
            $table->unsignedInteger('evidence_count')->default(0)->comment('Evidence counted in it');
            $table->unsignedBigInteger('odp_document_id')->nullable()->comment('The ODP version in force then (a record, not a key)');
            $table->foreignId('form_upload_id')->nullable()->comment('Baseline 1: the approved form upload')->constrained();
            $table->timestampTz('set_at');
            $table->foreignId('set_by')->nullable()->constrained('users');
            $table->timestampTz('superseded_at')->nullable()->comment('Set aside when its follow-up was reopened; kept');
            $table->foreignId('superseded_by')->nullable()->constrained('users');
            $table->string('superseded_reason', 2000)->nullable();
        });
        DB::statement('ALTER TABLE baselines ADD CONSTRAINT baselines_number CHECK (number BETWEEN 1 AND 4)');
        DB::statement('ALTER TABLE baselines ADD CONSTRAINT baselines_follow_up CHECK ((number = 1 AND follow_up_number IS NULL) OR follow_up_number = number - 1)');
        DB::statement('CREATE UNIQUE INDEX baselines_one_in_force ON baselines (assessment_id, number) WHERE superseded_at IS NULL');

        Schema::create('baseline_scores', function (Blueprint $table) {
            $table->foreignId('baseline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->decimal('points', 6, 2);
            $table->unsignedSmallInteger('max_points_at_scoring');
            $table->primary(['baseline_id', 'category_id']);
        });
        DB::statement('ALTER TABLE baseline_scores ADD CONSTRAINT baseline_scores_points_in_range CHECK (points >= 0 AND points <= max_points_at_scoring)');
        DB::statement('ALTER TABLE baseline_scores ADD CONSTRAINT baseline_scores_max_positive CHECK (max_points_at_scoring > 0)');

        // Frozen once recorded. A baseline may only be set aside, once, and nothing else changed.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION baselines_reject_update() RETURNS trigger AS $$
            BEGIN
                IF OLD.superseded_at IS NULL AND NEW.superseded_at IS NOT NULL
                   AND (to_jsonb(NEW) - 'superseded_at' - 'superseded_by' - 'superseded_reason')
                     = (to_jsonb(OLD) - 'superseded_at' - 'superseded_by' - 'superseded_reason') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'baselines are frozen once recorded: only setting one aside is allowed'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER baselines_frozen
                BEFORE UPDATE ON baselines
                FOR EACH ROW EXECUTE PROCEDURE baselines_reject_update();

            CREATE OR REPLACE FUNCTION baseline_scores_reject_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'baseline_scores are frozen once recorded: UPDATE is not allowed'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER baseline_scores_frozen
                BEFORE UPDATE ON baseline_scores
                FOR EACH ROW EXECUTE PROCEDURE baseline_scores_reject_update();
            SQL);

        Schema::table('follow_up_windows', function (Blueprint $table) {
            $table->jsonb('answers')->nullable()->comment('While open: the answers re-scored for its baseline; NULL: unchanged from the baseline before');
            $table->jsonb('answer_changes')->nullable()->comment('While open: the answers changed since the baseline before, with the reasons');
        });

        Schema::create('rescore_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('follow_up_number');
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('note', 2000)->nullable();
            $table->jsonb('changed')->comment('The question codes whose answer it changed');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestampTz('uploaded_at')->useCurrent();
            $table->index(['assessment_id', 'follow_up_number']);
        });

        $this->backfillFirstBaselines();
        $this->createViews();

        // The demo site: record changes on the tables added since the recording was set up
        // (the follow-up windows, the baselines, the re-uploaded forms), so a visitor's work
        // on them can be undone too. As 2026_11_10_000900 does; oha:demo-track-changes does the same.
        if (config('oha.demo') && DB::selectOne('select current_schema() as s')->s === 'public') {
            DemoChanges::install();
        }
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_movement_status');
        DB::statement('DROP VIEW IF EXISTS v_assessment_scores');
        DB::statement('DROP VIEW IF EXISTS v_baseline_scores');
        DB::statement('DROP VIEW IF EXISTS v_current_category_scores');
        $this->assessmentScores('category_scores', withBaseline: false);
        $this->movementStatus();

        Schema::dropIfExists('rescore_uploads');
        Schema::table('follow_up_windows', function (Blueprint $table) {
            $table->dropColumn(['answers', 'answer_changes']);
        });
        Schema::dropIfExists('baseline_scores');
        Schema::dropIfExists('baselines');
        DB::unprepared('DROP FUNCTION IF EXISTS baseline_scores_reject_update()');
        DB::unprepared('DROP FUNCTION IF EXISTS baselines_reject_update()');
    }

    /**
     * Assessments closed before baselines were recorded: their approved form becomes
     * Baseline 1, as closing them now would record it.
     */
    private function backfillFirstBaselines(): void
    {
        $closed = DB::table('assessments as a')
            ->whereNotNull('a.closed_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_scores as cs')->whereColumn('cs.assessment_id', 'a.id'))
            ->get(['a.id', 'a.closed_at', 'a.closed_by']);

        foreach ($closed as $assessment) {
            $uploadId = DB::table('category_scores')->where('assessment_id', $assessment->id)->value('form_upload_id');
            $answers = json_decode((string) DB::table('form_uploads')->where('id', $uploadId)->value('answers'), true) ?? [];
            $tasks = DB::table('odp_tasks')->where('assessment_id', $assessment->id)->whereNull('removed_at');

            $id = DB::table('baselines')->insertGetId([
                'assessment_id' => $assessment->id,
                'number' => 1,
                'answers' => json_encode($answers),
                'evidence_gaps' => json_encode($this->evidenceGaps($assessment->id, $answers)),
                'tasks_done' => (clone $tasks)->where('done', true)->count(),
                'tasks_total' => (clone $tasks)->count(),
                'evidence_count' => DB::table('evidence')->where('assessment_id', $assessment->id)->whereNull('counted_in')->count(),
                'form_upload_id' => $uploadId,
                'set_at' => $assessment->closed_at,
                'set_by' => $assessment->closed_by,
            ]);
            DB::statement(<<<'SQL'
                INSERT INTO baseline_scores (baseline_id, category_id, points, max_points_at_scoring)
                SELECT ?, category_id, points, max_points_at_scoring FROM category_scores WHERE assessment_id = ?
                SQL, [$id, $assessment->id]);
        }
    }

    /**
     * The evidence questions answered "Yes" with no evidence counted in the assessment.
     *
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    private function evidenceGaps(int $assessmentId, array $answers): array
    {
        $proven = DB::table('evidence_questions as eq')
            ->join('evidence as e', 'e.id', '=', 'eq.evidence_id')
            ->where('e.assessment_id', $assessmentId)->whereNull('e.counted_in')
            ->distinct()->pluck('eq.question_code')->all();

        return array_values(array_filter(array_keys(EvidenceQuestions::LABELS),
            fn (string $code) => Answer::isYes($answers[$code] ?? null) && ! in_array($code, $proven, true)));
    }

    private function createViews(): void
    {
        // Each assessment's score as it stands: its newest baseline in force, or (before it is closed) its approved form.
        DB::statement(<<<'SQL'
            CREATE VIEW v_current_category_scores AS
            SELECT b.assessment_id, bs.category_id, bs.points, bs.max_points_at_scoring, b.number AS baseline
            FROM baselines b
            JOIN baseline_scores bs ON bs.baseline_id = b.id
            WHERE b.superseded_at IS NULL
              AND b.number = (SELECT MAX(b2.number) FROM baselines b2 WHERE b2.assessment_id = b.assessment_id AND b2.superseded_at IS NULL)
            UNION ALL
            SELECT cs.assessment_id, cs.category_id, cs.points, cs.max_points_at_scoring, 1 AS baseline
            FROM category_scores cs
            WHERE NOT EXISTS (SELECT 1 FROM baselines b WHERE b.assessment_id = cs.assessment_id AND b.superseded_at IS NULL)
            SQL);

        // Points, percentage (one decimal) and band of every baseline, as v_assessment_scores derives them.
        DB::statement(<<<'SQL'
            CREATE VIEW v_baseline_scores AS
            SELECT t.baseline_id,
                   t.assessment_id,
                   t.number,
                   t.points_achieved,
                   t.points_available,
                   t.pct,
                   band.code AS band_code
            FROM (
                SELECT b.id AS baseline_id,
                       b.assessment_id,
                       b.number,
                       SUM(bs.points) AS points_achieved,
                       SUM(bs.max_points_at_scoring) AS points_available,
                       ROUND(SUM(bs.points) * 100 / SUM(bs.max_points_at_scoring), 1) AS pct
                FROM baselines b
                JOIN baseline_scores bs ON bs.baseline_id = b.id
                GROUP BY b.id
            ) t
            CROSS JOIN LATERAL (
                SELECT hb.code FROM health_bands hb
                WHERE hb.min_pct <= t.pct
                ORDER BY hb.min_pct DESC
                LIMIT 1
            ) band
            SQL);

        DB::statement('DROP VIEW IF EXISTS v_movement_status');
        DB::statement('DROP VIEW IF EXISTS v_assessment_scores');
        $this->assessmentScores('v_current_category_scores', withBaseline: true);
        $this->movementStatus();
    }

    private function assessmentScores(string $source, bool $withBaseline): void
    {
        $baseline = $withBaseline ? ",\n                   t.baseline" : '';
        $baselineInner = $withBaseline ? ",\n                       MAX(cs.baseline) AS baseline" : '';

        DB::statement(<<<SQL
            CREATE VIEW v_assessment_scores AS
            SELECT t.assessment_id,
                   t.movement_id,
                   t.assessed_on,
                   t.points_achieved,
                   t.points_available,
                   t.pct,
                   band.code AS band_code{$baseline}
            FROM (
                SELECT a.id AS assessment_id,
                       a.movement_id,
                       a.assessed_on,
                       SUM(cs.points) AS points_achieved,
                       SUM(cs.max_points_at_scoring) AS points_available,
                       ROUND(SUM(cs.points) * 100 / SUM(cs.max_points_at_scoring), 1) AS pct{$baselineInner}
                FROM assessments a
                JOIN {$source} cs ON cs.assessment_id = a.id
                GROUP BY a.id
            ) t
            CROSS JOIN LATERAL (
                SELECT hb.code FROM health_bands hb
                WHERE hb.min_pct <= t.pct
                ORDER BY hb.min_pct DESC
                LIMIT 1
            ) band
            SQL);
    }

    /** As 2026_11_12_000400 left it: the next assessment only for a movement never assessed. */
    private function movementStatus(): void
    {
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
                SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM assessments x WHERE x.movement_id = m.id)
                            THEN m.planned_assessment_on
                       END AS next_assessment_on
            ) n
            SQL);
    }
};
