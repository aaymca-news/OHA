<?php

namespace App\Support;

use App\Enums\ArtefactKind;
use App\Models\Assessment;
use App\Models\Category;
use App\Models\CategoryScore;
use App\Models\Movement;
use App\Models\MovementStatus;
use Illuminate\Support\Collection;

/**
 * The Alliance's scores as a spreadsheet for the Secretariat's reporting: one row per
 * movement with its latest approved score (movements not yet rated have blank scores),
 * or with $every, one row per approved assessment. Each category as a percentage, the
 * total in points and percent, the band, and whether the ODP is validated.
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
        /** @var Collection<int, Collection<int, CategoryScore>> $scores */
        $scores = CategoryScore::query()->whereIn('assessment_id', $assessments->pluck('id'))->get()
            ->groupBy('assessment_id')->map(fn (Collection $s) => $s->keyBy('category_id'));
        $byMovement = $assessments->groupBy('movement_id');

        $rows = [[
            'Movement', 'Country', 'Zone', 'Membership', 'Assessment', 'Assessed (month)',
            ...$categories->map(fn (Category $c) => $c->name.' (%)')->all(),
            'Points', 'Out of', 'Score (%)', 'Band', 'ODP validated', 'Next assessment due',
        ]];

        foreach ($movements as $movement) {
            $theirs = $byMovement->get($movement->id, collect());
            $next = $movement->status?->next_assessment_on;
            $start = [$movement->name, $movement->country, $movement->zone->name, $movement->membershipStatus->label];

            if ($theirs->isEmpty()) {
                if (! $every) {
                    $rows[] = [...$start, null, null, ...array_fill(0, $categories->count(), null), null, null, null, 'Not yet rated', null, $next?->format('Y-m')];
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
                    // Only the latest assessment sets when the next is due.
                    $i === 0 ? $next?->format('Y-m') : null,
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
