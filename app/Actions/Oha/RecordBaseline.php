<?php

namespace App\Actions\Oha;

use App\Models\Assessment;
use App\Models\Baseline;
use App\Models\BaselineScore;
use App\Models\Category;
use App\Models\Evidence;
use App\Models\User;
use App\Oha\Rescoring;
use App\Oha\Scorer;
use App\Support\Audit;
use App\Support\OdpTimeline;

/**
 * Records an assessment's baselines (decided 12 Nov 2026), as part of the step that sets
 * them, inside its transaction (no policy of its own):
 *
 *   first          when the assessment is closed: Baseline 1, the approved form's scores
 *                  exactly as recorded (category_scores) and the answers they came from
 *   afterFollowUp  when follow-up N is closed: Baseline N + 1, the answers re-scored during
 *                  it (or, unchanged, the baseline before), scored by the form's own
 *                  formulas (Scorer), with what changed and why
 *   setAside       when an Administrator reopens follow-up N: its Baseline N + 1 is set
 *                  aside (kept), and recorded anew when the follow-up is closed again
 *
 * Each keeps the "Yes" answers with no evidence counted yet, the ODP's progress and the
 * evidence counted in it. A baseline is never edited (the database refuses).
 */
final class RecordBaseline
{
    public function __construct(private readonly Scorer $scorer) {}

    /** Baseline 1; null when there is no approved form to record (it is then never scored). */
    public function first(Assessment $assessment, ?User $by): ?Baseline
    {
        $existing = Rescoring::inForce($assessment, 1);
        if ($existing !== null) {
            return $existing;
        }

        $scores = $assessment->categoryScores()->get();
        $upload = $scores->isNotEmpty()
            ? $scores->first()->formUpload
            : $assessment->form()->first()?->uploads()->whereNotNull('approved_at')->orderByDesc('id')->first();
        if ($upload === null) {
            return null;
        }
        $answers = $upload->answers ?? [];
        $points = $scores->isNotEmpty()
            ? $scores->mapWithKeys(fn ($s) => [$s->category_id => ['points' => $s->points, 'max' => $s->max_points_at_scoring]])->all()
            : $this->points($answers, $upload->form_meta['missing_sheets'] ?? []);

        return $this->store($assessment, $by, 1, null, $answers, null, $points, [
            'evidence_gaps' => Rescoring::evidenceGaps($assessment, $answers, null),
            'evidence_count' => Evidence::query()->where('assessment_id', $assessment->id)->whereNull('counted_in')->count(),
            'odp_document_id' => OdpTimeline::source($assessment)?->id,
            'form_upload_id' => $upload->id,
        ]);
    }

    /** Baseline $followUp + 1; null when the assessment has no baseline to start from. */
    public function afterFollowUp(Assessment $assessment, int $followUp, ?User $by): ?Baseline
    {
        $start = Rescoring::startingPoint($assessment, $followUp) ?? $this->first($assessment, $by);
        if ($start === null) {
            return null;
        }
        $window = Rescoring::window($assessment, $followUp);
        $answers = $window->answers ?? $start->answers;
        $changes = Rescoring::changes($window?->answer_changes, $start->answers, $answers);
        $tasks = $assessment->odpTasks()->get();

        return $this->store($assessment, $by, $followUp + 1, $followUp, $answers, $changes === [] ? null : $changes, $this->points($answers, []), [
            'evidence_gaps' => Rescoring::evidenceGaps($assessment, $answers, $followUp),
            'tasks_done' => $tasks->where('done', true)->count(),
            'tasks_total' => $tasks->count(),
            'evidence_count' => Evidence::query()->where('assessment_id', $assessment->id)->where('counted_in', $followUp)->count(),
            'odp_document_id' => OdpTimeline::source($assessment)?->id,
        ]);
    }

    /** Sets aside the baseline follow-up $followUp recorded, if one is in force: it is kept. */
    public function setAside(Assessment $assessment, int $followUp, User $by, string $reason): void
    {
        $baseline = Rescoring::inForce($assessment, $followUp + 1);
        if ($baseline === null) {
            return;
        }
        $baseline->forceFill(['superseded_at' => now(), 'superseded_by' => $by->id, 'superseded_reason' => $reason])->save();
        Audit::record($by, 'baseline.set_aside', $baseline, $assessment, payload: ['number' => $baseline->number, 'reason' => $reason]);
    }

    /**
     * Points per weighted category, by the form's own scoring.
     *
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $missingSheets
     * @return array<int, array{points: int|float|string, max: int}> category id => points and maximum
     */
    private function points(array $answers, array $missingSheets): array
    {
        $scored = $this->scorer->score($answers, $missingSheets);
        $points = [];
        foreach (Category::query()->weighted()->get() as $category) {
            $points[$category->id] = ['points' => $scored[$category->code] ?? 0, 'max' => (int) $category->max_points];
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>|null  $changes
     * @param  array<int, array{points: int|float|string, max: int}>  $points
     * @param  array<string, mixed>  $facts
     */
    private function store(Assessment $assessment, ?User $by, int $number, ?int $followUp, array $answers, ?array $changes, array $points, array $facts): Baseline
    {
        $baseline = Baseline::query()->create([
            'assessment_id' => $assessment->id,
            'number' => $number,
            'follow_up_number' => $followUp,
            'answers' => $answers,
            'changes' => $changes,
            'set_at' => now(),
            'set_by' => $by?->id,
        ] + $facts);

        foreach ($points as $categoryId => $score) {
            BaselineScore::query()->create([
                'baseline_id' => $baseline->id,
                'category_id' => $categoryId,
                'points' => $score['points'],
                'max_points_at_scoring' => $score['max'],
            ]);
        }

        Audit::record($by, 'baseline.recorded', $baseline, $assessment, payload: array_filter([
            'number' => $number,
            'follow_up' => $followUp,
            'points' => array_sum(array_map(fn ($s) => (float) $s['points'], $points)),
            'out_of' => array_sum(array_column($points, 'max')),
            'answers_changed' => $changes !== null ? count($changes) : null,
        ], fn ($v) => $v !== null));

        return $baseline;
    }
}
