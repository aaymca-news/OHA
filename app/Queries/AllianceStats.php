<?php

namespace App\Queries;

use App\Enums\BandCode;
use App\Models\Assessment;
use App\Models\Category;
use App\Models\HealthBand;
use App\Models\Movement;
use App\Models\MovementStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alliance-wide figures for the dashboard, read from the views: every number
 * here is the one every other screen shows.
 */
final class AllianceStats
{
    /**
     * @return array{total: int, assessed: int, mean: float|null, at_risk: int, with_odp: int, overdue: int}
     */
    public function headline(): array
    {
        $statuses = MovementStatus::query()->get();
        $assessed = $statuses->whereNotNull('latest_assessment_id');

        return [
            'total' => $statuses->count(),
            'assessed' => $assessed->count(),
            'mean' => $assessed->isEmpty() ? null : round($assessed->avg(fn (MovementStatus $s) => (float) $s->pct), 1),
            'at_risk' => $statuses->whereIn('band_code', BandCode::NEEDING_SUPPORT)->count(),
            'with_odp' => $statuses->where('has_odp', true)->count(),
            'overdue' => $statuses->where('reassessment_overdue', true)->count(),
        ];
    }

    /**
     * How many movements sit in each band, best band first. The movements not yet rated
     * are split: those whose assessment has started, then those not started.
     *
     * @return Collection<int, array{band: HealthBand, count: int, underWay: bool}>
     */
    public function bandDistribution(): Collection
    {
        $counts = MovementStatus::query()->select('band_code', DB::raw('count(*) as n'))->groupBy('band_code')->pluck('n', 'band_code');
        $started = MovementStatus::query()->where('band_code', 'notstarted')
            ->whereIn('movement_id', array_keys(Movement::withAssessmentUnderWay()))->count();

        $rows = [];
        foreach (HealthBand::query()->orderBy('sort_order')->get() as $band) {
            $count = (int) ($counts[$band->code] ?? 0);
            if ($band->code === 'notstarted') {
                $rows[] = ['band' => $band, 'count' => $started, 'underWay' => true];
                $count -= $started;
            }
            $rows[] = ['band' => $band, 'count' => $count, 'underWay' => false];
        }

        return collect($rows);
    }

    /**
     * Each weighted category's average score across every movement's latest
     * validated assessment, weakest first.
     *
     * @return Collection<int, array{category: Category, pct: float, movements: int}>
     */
    public function weakestCategories(): Collection
    {
        // Each movement's score as it stands: its newest baseline.
        $averages = DB::table('v_current_category_scores as cs')
            ->join('v_movement_status as ms', 'ms.latest_assessment_id', '=', 'cs.assessment_id')
            ->select('cs.category_id', DB::raw('avg(cs.points * 100.0 / cs.max_points_at_scoring) as pct'), DB::raw('count(*) as n'))
            ->groupBy('cs.category_id')
            ->get()->keyBy('category_id');

        return Category::query()->weighted()->orderBy('form_order')->get()
            ->filter(fn (Category $c) => $averages->has($c->id))
            ->map(fn (Category $c) => ['category' => $c, 'pct' => round((float) $averages[$c->id]->pct, 1), 'movements' => (int) $averages[$c->id]->n])
            ->sortBy('pct')->values();
    }

    /**
     * Movements ready for their next assessment: their latest cycle is closed and no new
     * assessment is under way (decided 12 Nov 2026: no band-based date), the longest
     * waiting first.
     *
     * @return Collection<int, MovementStatus>
     */
    public function readyForNext(): Collection
    {
        $closed = Assessment::query()->latestPerMovement()->whereNotNull('follow_up_closed_at')->pluck('follow_up_closed_at', 'movement_id');

        return MovementStatus::query()->with(['band', 'movement'])->whereIn('movement_id', $closed->keys())->get()
            ->sortBy(fn (MovementStatus $s) => [$closed[$s->movement_id], $s->name])->values();
    }
}
