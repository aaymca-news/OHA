<?php

namespace App\Queries;

use App\Models\Assessment;
use App\Models\Baseline;
use App\Models\FormUpload;
use App\Models\HealthBand;
use App\Models\Movement;
use App\Oha\CategoryRows;
use App\Support\Viz;
use Illuminate\Support\Collection;

/**
 * The journey of an assessment cycle (decided 12 Nov 2026): its baselines in force, from
 * Baseline 1 (the closed assessment) to the newest (after each follow-up), each with its
 * score, band, scores by category, ODP progress, evidence counted and answers changed.
 * For one movement, and across the Alliance (each movement's latest closed assessment).
 * Every figure is read from the baselines as recorded (v_baseline_scores), never worked out
 * again, so the journey shows what every other screen showed at the time.
 */
final class Journeys
{
    /** The number of baselines a cycle can have: the assessment and its follow-ups. */
    public static function slots(): int
    {
        return 1 + (int) config('oha.follow_ups');
    }

    /**
     * One assessment's journey, Baseline 1 first; empty before it is closed.
     *
     * @return list<array{baseline: Baseline, number: int, label: string, pct: float, points: float, max: int, band: HealthBand|null, rows: array<string, array<string, mixed>>, tasks_done: int|null, tasks_total: int|null, evidence: int, changed: int}>
     */
    public function of(Assessment $assessment): array
    {
        $baselines = $assessment->baselines()->whereNull('superseded_at')->with('total.band')->get();

        return $baselines->map(fn (Baseline $b) => $this->step($assessment, $b, withRows: true))->values()->all();
    }

    /**
     * Each movement's latest closed assessment and its journey so far, the biggest gain
     * first; and the Alliance's figures across them.
     *
     * @return array{rows: list<array{movement: Movement, assessment: Assessment, first: array<string, mixed>, now: array<string, mixed>, change: float, band_moved: int, follow_ups: int}>, summary: array{movements: int, followed: int, improved: int, declined: int, band_up: int, mean_change: float|null}, curve: list<array{number: int, pct: float|null, n: int}>}
     */
    public function alliance(): array
    {
        $assessments = Assessment::query()->latestPerMovement(fn ($q) => $q->whereNotNull('closed_at'))
            ->with(['movement', 'baselines' => fn ($q) => $q->whereNull('superseded_at')->with('total.band')])
            ->get()->filter(fn (Assessment $a) => $a->baselines->isNotEmpty());
        $order = HealthBand::query()->pluck('min_pct', 'code')->map(fn ($v) => (float) $v);

        $rows = [];
        $byNumber = [];
        foreach ($assessments as $assessment) {
            $steps = $assessment->baselines->map(fn (Baseline $b) => $this->step($assessment, $b, withRows: false))->values();
            foreach ($steps as $step) {
                $byNumber[$step['number']][] = $step['pct'];
            }
            $first = $steps->first();
            $now = $steps->last();
            $rows[] = [
                'movement' => $assessment->movement,
                'assessment' => $assessment,
                'first' => $first,
                'now' => $now,
                'change' => round($now['pct'] - $first['pct'], 1),
                'band_moved' => ($order[$now['band']->code ?? ''] ?? 0) <=> ($order[$first['band']->code ?? ''] ?? 0),
                'follow_ups' => $steps->count() - 1,
            ];
        }
        usort($rows, fn (array $a, array $b) => [$b['change'], $a['movement']->name] <=> [$a['change'], $b['movement']->name]);
        $followed = collect($rows)->where('follow_ups', '>', 0);

        $curve = [];
        for ($n = 1; $n <= self::slots(); $n++) {
            $values = $byNumber[$n] ?? [];
            $curve[] = ['number' => $n, 'pct' => $values === [] ? null : round(array_sum($values) / count($values), 1), 'n' => count($values)];
        }

        return [
            'rows' => $rows,
            'summary' => [
                'movements' => count($rows),
                'followed' => $followed->count(),
                'improved' => $followed->where('change', '>', 0)->count(),
                'declined' => $followed->where('change', '<', 0)->count(),
                'band_up' => $followed->where('band_moved', 1)->count(),
                'mean_change' => $followed->isEmpty() ? null : round($followed->avg('change'), 1),
            ],
            'curve' => $curve,
        ];
    }

    /**
     * Each question's wording, as the approved form (Baseline 1's) gave it.
     *
     * @return array<string, string>
     */
    public function wording(Assessment $assessment): array
    {
        $uploadId = Baseline::query()->where('assessment_id', $assessment->id)->where('number', 1)->whereNull('superseded_at')->value('form_upload_id');
        $meta = $uploadId !== null ? FormUpload::query()->whereKey($uploadId)->value('form_meta') : null;
        $labels = is_string($meta) ? (json_decode($meta, true)['labels'] ?? []) : ($meta['labels'] ?? []);

        return array_map(fn ($text) => ltrim((string) $text, '- '), is_array($labels) ? $labels : []);
    }

    /**
     * The points of a growth curve: one per baseline slot (1 to 4), null where not reached yet.
     *
     * @param  list<array{number: int, pct: float, points: float, max: int, band: HealthBand|null, label: string}>|Collection<int, array<string, mixed>>  $steps
     * @return list<array{label: string, pct: float|null, note: string}>
     */
    public static function curve(array|Collection $steps): array
    {
        $byNumber = collect($steps)->keyBy('number');
        $points = [];
        for ($n = 1; $n <= self::slots(); $n++) {
            $step = $byNumber[$n] ?? null;
            $points[] = [
                'label' => 'Baseline '.$n,
                'pct' => $step['pct'] ?? null,
                'note' => $step === null ? ($n === 1 ? 'Not recorded' : 'After follow-up '.($n - 1).', not yet')
                    : Viz::figure($step['points']).' of '.$step['max'].' points · '.($step['band']->label ?? ''),
            ];
        }

        return $points;
    }

    /**
     * @return array{baseline: Baseline, number: int, label: string, pct: float, points: float, max: int, band: HealthBand|null, rows: array<string, array<string, mixed>>, tasks_done: int|null, tasks_total: int|null, evidence: int, changed: int}
     */
    private function step(Assessment $assessment, Baseline $baseline, bool $withRows): array
    {
        return [
            'baseline' => $baseline,
            'number' => $baseline->number,
            'label' => 'Baseline '.$baseline->number,
            'pct' => (float) $baseline->total?->pct,
            'points' => (float) $baseline->total?->points_achieved,
            'max' => (int) $baseline->total?->points_available,
            'band' => $baseline->total?->band,
            'rows' => $withRows ? collect(CategoryRows::for($assessment, $baseline))->keyBy('code')->all() : [],
            'tasks_done' => $baseline->tasks_done,
            'tasks_total' => $baseline->tasks_total,
            'evidence' => $baseline->evidence_count,
            'changed' => count($baseline->changes ?? []),
        ];
    }
}
