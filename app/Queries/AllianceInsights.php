<?php

namespace App\Queries;

use App\Models\Assessment;
use App\Models\Category;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\WorkItem;
use App\Oha\Profile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alliance-wide analytics drawn from each movement's latest approved OHA form: the
 * scores it froze, and what its answers say (funding, people, policies, strategy).
 * Money is never added up across movements, since each reports in its own currency;
 * only shares and ratios are compared.
 */
final class AllianceInsights
{
    /** @var Collection<int, array{movement: Movement, status: MovementStatus, profile: array<string, mixed>}>|null */
    private ?Collection $rated = null;

    /**
     * Each rated movement with its status and form profile, keyed by movement id.
     *
     * @return Collection<int, array{movement: Movement, status: MovementStatus, profile: array<string, mixed>}>
     */
    public function rated(): Collection
    {
        if ($this->rated !== null) {
            return $this->rated;
        }

        $statuses = MovementStatus::query()->with(['movement.zone', 'band'])->whereNotNull('latest_assessment_id')->orderBy('name')->get();
        $uploads = DB::table('category_scores as cs')
            ->join('form_uploads as fu', 'fu.id', '=', 'cs.form_upload_id')
            ->whereIn('cs.assessment_id', $statuses->pluck('latest_assessment_id'))
            ->select('cs.assessment_id', 'fu.answers', 'fu.form_meta')->distinct()->get()->keyBy('assessment_id');

        $rated = [];
        foreach ($statuses as $status) {
            $movement = $status->movement;
            if ($movement === null) {
                continue;
            }
            $upload = $uploads[$status->latest_assessment_id] ?? null;
            $profile = $upload !== null
                ? (new Profile(json_decode($upload->answers, true) ?? [], json_decode($upload->form_meta, true) ?? []))->toArray()
                : [];

            $rated[$movement->id] = ['movement' => $movement, 'status' => $status, 'profile' => $profile];
        }

        return $this->rated = collect($rated);
    }

    /**
     * Where every movement is in Stage 1: not assessed, which step is under way, or complete.
     *
     * @return array{not_assessed: int, form: int, report: int, odp: int, sign: int, complete: int, total: int}
     */
    public function pipeline(): array
    {
        $latest = Assessment::query()->select('movement_id', DB::raw('max(id) as id'))->groupBy('movement_id')->pluck('id', 'movement_id');
        $work = WorkItem::query()->whereIn('assessment_id', $latest->values())->get()->keyBy('assessment_id');
        $total = Movement::query()->count();
        $counts = ['not_assessed' => $total - $latest->count(), 'form' => 0, 'report' => 0, 'odp' => 0, 'sign' => 0, 'complete' => 0, 'total' => $total];

        foreach ($latest as $assessmentId) {
            $item = $work[$assessmentId] ?? null;
            $key = $item === null ? 'complete' : ($item->holder_role->value === 'board' ? 'sign' : $item->artefact_kind->value);
            $counts[$key]++;
        }

        return $counts;
    }

    /**
     * Movements × categories: each cell the percentage scored, for the heatmap.
     *
     * @return array{categories: Collection<int, Category>, rows: list<array{movement: Movement, status: MovementStatus, cells: array<string, float|null>}>}
     */
    public function heatmap(): array
    {
        $categories = Category::query()->weighted()->orderBy('form_order')->get();
        $scores = DB::table('category_scores')->whereIn('assessment_id', $this->rated()->pluck('status.latest_assessment_id'))
            ->get()->groupBy('assessment_id');

        $rows = $this->rated()->map(function (array $r) use ($categories, $scores) {
            $mine = collect($scores[$r['status']->latest_assessment_id] ?? [])->keyBy('category_id');

            return [
                'movement' => $r['movement'],
                'status' => $r['status'],
                'cells' => $categories->mapWithKeys(fn (Category $c) => [$c->code => isset($mine[$c->id])
                    ? round($mine[$c->id]->points * 100 / $mine[$c->id]->max_points_at_scoring, 1) : null])->all(),
            ];
        })->sortByDesc(fn ($row) => (float) $row['status']->pct)->values()->all();

        return ['categories' => $categories, 'rows' => $rows];
    }

    /**
     * Average score per zone, with how many movements it rests on.
     *
     * @return Collection<int, array{zone: string, pct: float, movements: int}>
     */
    public function zones(): Collection
    {
        return $this->rated()->groupBy(fn ($r) => $r['movement']->zone->name)
            ->map(fn (Collection $rows, string $zone) => ['zone' => $zone, 'pct' => round($rows->avg(fn ($r) => (float) $r['status']->pct), 1), 'movements' => $rows->count()])
            ->sortByDesc('pct')->values();
    }

    /**
     * For each Yes/No item, the share of rated movements answering Yes.
     *
     * @param  array<string, string>  $labels
     * @return list<array{code: string, label: string, yes: int, answered: int, pct: float}>
     */
    public function adoption(string $section, array $labels): array
    {
        $rows = [];
        foreach ($labels as $code => $label) {
            $answers = $this->rated()->map(fn ($r) => $r['profile'][$section][$code] ?? null)->filter(fn ($v) => $v !== null);
            $yes = $answers->filter()->count();
            $rows[] = ['code' => $code, 'label' => $label, 'yes' => $yes, 'answered' => $answers->count(),
                'pct' => $answers->isEmpty() ? 0.0 : round($yes * 100 / $answers->count(), 1)];
        }

        usort($rows, fn ($a, $b) => $b['pct'] <=> $a['pct']);

        return $rows;
    }

    /**
     * The average funding or spending mix across movements that gave one, normalised to 100.
     *
     * @return array{shares: array<string, float>, movements: int}|null
     */
    public function averageMix(string $key): ?array
    {
        $mixes = $this->rated()->map(fn ($r) => $r['profile'][$key] ?? null)->filter();
        if ($mixes->isEmpty()) {
            return null;
        }

        $normalised = $mixes->map(function (array $mix) {
            $sum = array_sum($mix) ?: 1;

            return array_map(fn ($v) => $v * 100 / $sum, $mix);
        });
        $keys = array_keys($mixes->first());

        return [
            'shares' => array_combine($keys, array_map(fn ($k) => round($normalised->avg(fn ($m) => $m[$k] ?? 0), 1), $keys)),
            'movements' => $mixes->count(),
        ];
    }

    /**
     * People across the alliance: head counts can be added up, unlike money.
     *
     * @return array<string, array{total: float, reporting: int}>
     */
    public function people(): array
    {
        $sum = function (callable $pick): array {
            $values = $this->rated()->map($pick)->filter(fn ($v) => $v !== null);

            return ['total' => (float) $values->sum(), 'reporting' => $values->count()];
        };

        return [
            'staff' => $sum(fn ($r) => $r['profile']['people']['staff'] ?? null),
            'volunteers' => $sum(fn ($r) => $r['profile']['people']['volunteers'] ?? null),
            'beneficiaries' => $sum(fn ($r) => $r['profile']['beneficiaries'] ?? null),
            'branches' => $sum(fn ($r) => $r['profile']['identity']['branches'] ?? null),
            'board' => $sum(fn ($r) => $r['profile']['board']['size'] ?? null),
            'board_women' => $sum(fn ($r) => isset($r['profile']['board']['size']) ? $r['profile']['board']['women'] : null),
            'board_under_30' => $sum(fn ($r) => isset($r['profile']['board']['size']) ? $r['profile']['board']['under_30'] : null),
        ];
    }

    /**
     * Per movement, the currency-free figures worth comparing: reliance on international
     * money, operating margin, and liabilities against assets.
     *
     * @return list<array{movement: Movement, international: float|null, margin: float|null, liabilities: float|null, restricted: float|null}>
     */
    public function finance(): array
    {
        return $this->rated()->map(fn ($r) => [
            'movement' => $r['movement'],
            'international' => $r['profile']['international_pct'] ?? null,
            'restricted' => $r['profile']['restricted_pct'] ?? null,
            'margin' => $r['profile']['finance']['margin_pct'] ?? null,
            'liabilities' => $r['profile']['finance']['liability_ratio'] ?? null,
        ])->values()->all();
    }
}
