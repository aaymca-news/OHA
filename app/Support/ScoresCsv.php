<?php

namespace App\Support;

use App\Enums\ArtefactKind;
use App\Enums\Stage;
use App\Models\Assessment;
use App\Models\Category;
use App\Models\Movement;
use App\Models\MovementStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Alliance's scores as a spreadsheet for the Secretariat's reporting: one row per
 * movement with its latest approved score (movements not yet rated have blank scores),
 * or with $every, one row per approved assessment. Each category as a percentage, the
 * total in points and percent, the band, whether the ODP is validated, and when the next
 * assessment comes (no band-based date: ready once the cycle is closed).
 */
final class ScoresCsv
{
    /** @return list<list<string|int|float|null>> */
    public function rows(bool $every): array
    {
        $categories = Category::query()->orderBy('form_order')->get();
        $movements = Movement::query()->with(['zone', 'membershipStatus', 'status.band'])->orderBy('name')->get();

        $assessments = Assessment::query()
            ->with(['score.band', 'artefacts.signature'])
            ->whereHas('score')
            ->when(! $every, fn ($q) => $q->whereIn('id', MovementStatus::query()->whereNotNull('latest_assessment_id')->select('latest_assessment_id')))
            ->orderByDesc('assessed_on')->orderByDesc('id')
            ->get();
        // Each assessment's score as it stands: its newest baseline (or, before it is closed, its approved form).
        /** @var Collection<int, Collection<int, object{category_id: int, points: string, max_points_at_scoring: int}>> $scores */
        $scores = DB::table('v_current_category_scores')->whereIn('assessment_id', $assessments->pluck('id'))->get()
            ->groupBy('assessment_id')->map(fn (Collection $s) => $s->keyBy('category_id'));
        $byMovement = $assessments->groupBy('movement_id');
        // Each closed assessment's Baseline 1 score, to show how far it moved since.
        $firsts = DB::table('v_baseline_scores as t')->join('baselines as b', 'b.id', '=', 't.baseline_id')
            ->whereNull('b.superseded_at')->where('t.number', 1)->whereIn('t.assessment_id', $assessments->pluck('id'))
            ->pluck('t.pct', 't.assessment_id');
        // When each movement's next assessment comes: no band-based date (decided 12 Nov 2026).
        $latestOf = Assessment::query()->latestPerMovement()->with('workItem')->get()->keyBy('movement_id');

        $rows = [[
            'Movement', 'Country', 'Zone', 'Membership', 'Assessment', 'Assessed (month)',
            ...$categories->map(fn (Category $c) => $c->name.' (%)')->all(),
            'Points', 'Out of', 'Score (%)', 'Band', 'ODP validated', 'Next assessment',
            // The baseline the score is (decided 12 Nov 2026), and how far it moved from Baseline 1.
            'Baseline', 'Baseline 1 (%)', 'Change since Baseline 1 (pts)',
        ]];

        foreach ($movements as $movement) {
            $theirs = $byMovement->get($movement->id, collect());
            $next = Stage::of($latestOf[$movement->id] ?? null)->nextAssessment($movement->status?->next_assessment_on)[0];
            $start = [$movement->name, $movement->country, $movement->zone->name, $movement->membershipStatus->label];

            if ($theirs->isEmpty()) {
                if (! $every) {
                    $rows[] = [...$start, null, null, ...array_fill(0, $categories->count(), null), null, null, null, 'Not yet rated', null, $next, null, null, null];
                }

                continue;
            }

            foreach ($theirs as $i => $assessment) {
                $own = $scores->get($assessment->id, collect());
                $odp = $assessment->artefacts->first(fn ($a) => $a->kind === ArtefactKind::Odp);
                $rows[] = [
                    ...$start,
                    $assessment->period_label,
                    $assessment->assessed_on->format('Y-m'),
                    ...$categories->map(function (Category $c) use ($own) {
                        $score = $own->get($c->id);

                        return $score === null || ! $score->max_points_at_scoring ? null : round((float) $score->points * 100 / $score->max_points_at_scoring, 1);
                    })->all(),
                    (float) $assessment->score->points_achieved,
                    $assessment->score->points_available,
                    (float) $assessment->score->pct,
                    $assessment->score->band->label,
                    $odp?->signature !== null ? 'Yes' : 'No',
                    // Only on the latest assessment's row.
                    $i === 0 ? $next : null,
                    $assessment->score->baseline,
                    isset($firsts[$assessment->id]) ? (float) $firsts[$assessment->id] : null,
                    isset($firsts[$assessment->id]) ? round((float) $assessment->score->pct - (float) $firsts[$assessment->id], 1) : null,
                ];
            }
        }

        return $rows;
    }

    /** The rows as CSV text that Excel opens with accents intact. */
    public function csv(bool $every): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($this->rows($every) as $row) {
            // Text that a spreadsheet would read as a formula is kept as text.
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row), escape: '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
