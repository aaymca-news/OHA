<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentMilestone;
use App\Models\FormUpload;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\OdpTask;
use App\Models\User;
use App\Oha\CategoryRows;
use App\Oha\Profile;
use App\Queries\AllianceInsights;
use App\Queries\AllianceStats;
use App\Queries\MyWork;
use App\Queries\OdpActivity;
use App\Support\OdpTimeline;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Two dashboards. AAYMCA (staff and Administrators): the region as a whole — how
 * healthy the movements are, where Stage 1 stands, and how the ODPs are being carried
 * out — with the detail by category further down. A National Movement's Board
 * Chairperson and National General Secretary: their own movement at a glance.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, MyWork $myWork, AllianceStats $stats, AllianceInsights $insights, OdpActivity $odps): View
    {
        $user = $request->user();
        $work = collect($myWork->for($user))
            ->flatMap(fn (array $group) => collect($group['items'])->where('actionable', true))
            ->take(6);

        if (Gate::forUser($user)->denies('viewAllianceStats')) {
            return $this->movement($user, $work);
        }

        $statuses = MovementStatus::query()->with(['band', 'movement.zone'])->orderByRaw('pct DESC NULLS LAST')->orderBy('name')->get();

        return view('dashboard.secretariat', [
            'headline' => $stats->headline(),
            'bands' => $stats->bandDistribution(),
            'weakest' => $stats->weakestCategories(),
            'due' => $stats->reassessmentDue(),
            'underWay' => Movement::withAssessmentUnderWay(),
            'work' => $work,
            'insights' => $insights,
            'pipeline' => $insights->pipeline(),
            'odpChanges' => $odps->latest($user),
            'odpSync' => $odps->sync(),
            'ranked' => $statuses,
            'zones' => $this->zones($statuses),
            'odp' => $this->odpProgress(OdpTimeline::assessments()),
        ]);
    }

    /**
     * The movement's own dashboard: its health, where its assessment stands, its scores
     * by category, how its ODP is going, and who to contact at AAYMCA.
     *
     * @param  Collection<int, mixed>  $work
     */
    private function movement(User $user, Collection $work): View
    {
        $movement = $user->movement;
        $status = MovementStatus::query()->with(['band', 'movement'])->findOrFail($user->movement_id);
        $gate = Gate::forUser($user);

        $scored = $status->latest_assessment_id !== null ? Assessment::query()->with('score.band')->find($status->latest_assessment_id) : null;
        $frozen = $scored !== null ? FormUpload::query()->whereKey($scored->categoryScores()->value('form_upload_id'))->first() : null;
        $profile = $frozen !== null ? (new Profile($frozen->answers ?? [], $frozen->form_meta ?? []))->toArray() : null;

        // The assessment under way, if any: where it stands, gate by gate.
        $current = $movement->assessments()->whereHas('workItem')->with('workItem')->latest('id')->first();

        // The ODP's tasks, once the ODP itself is theirs to see.
        $odpAssessment = OdpTimeline::assessments([$movement->id])->first();
        $odpArtefact = $odpAssessment?->odp()->first();
        $showOdp = $odpArtefact !== null && $gate->allows('view', $odpArtefact);
        if ($showOdp) {
            OdpTimeline::refresh($odpAssessment);
        }
        $tasks = $showOdp ? OdpTask::query()->where('assessment_id', $odpAssessment->id)->orderBy('sort_order')->orderBy('id')->get() : collect();

        return view('dashboard.board', [
            'movement' => $movement,
            'status' => $status,
            'work' => $work,
            'latest' => $scored,
            'rows' => $scored !== null ? CategoryRows::for($scored) : [],
            'profile' => $profile,
            'current' => $current,
            'milestones' => $current !== null ? AssessmentMilestone::query()->where('assessment_id', $current->id)->orderBy('sort_order')->get() : collect(),
            'odpAssessment' => $showOdp ? $odpAssessment : null,
            'tasks' => $tasks,
            'assessors' => $movement->assessors()->where('active', true)->orderBy('name')->get(),
            'assessments' => Assessment::query()->with('score.band')->where('movement_id', $movement->id)->latest('assessed_on')->latest('id')->get()
                ->filter(fn (Assessment $a) => $gate->allows('view', $a)),
        ]);
    }

    /**
     * Per zone: how many movements, how many rated, their average score, and how many
     * are At Risk or Critical.
     *
     * @param  Collection<int, MovementStatus>  $statuses
     * @return Collection<int, array{zone: string, movements: int, rated: int, pct: float|null, at_risk: int}>
     */
    private function zones(Collection $statuses): Collection
    {
        return $statuses->filter(fn (MovementStatus $s) => $s->movement !== null)
            ->groupBy(fn (MovementStatus $s) => $s->movement->zone->name)
            ->map(function (Collection $rows, string $zone): array {
                $rated = $rows->whereNotNull('latest_assessment_id');

                return [
                    'zone' => $zone,
                    'movements' => $rows->count(),
                    'rated' => $rated->count(),
                    'pct' => $rated->isEmpty() ? null : round($rated->avg(fn (MovementStatus $s) => (float) $s->pct), 1),
                    'at_risk' => $rows->whereIn('band_code', ['atrisk', 'critical'])->count(),
                ];
            })->sortBy('zone')->values();
    }

    /**
     * How the ODPs are being carried out: every task's state across the region, and
     * per movement, how many of its tasks are done and how many are overdue.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @return array{counts: Collection<array-key, int>, total: int, rows: Collection<int, array{movement: Movement|null, all: positive-int, done: int, overdue: int}>}
     */
    private function odpProgress(Collection $assessments): array
    {
        $tasks = OdpTask::query()->whereIn('assessment_id', $assessments->pluck('id'))->get();
        $by = $tasks->groupBy('assessment_id');

        return [
            'counts' => $tasks->countBy(fn (OdpTask $t) => $t->state()),
            'total' => $tasks->count(),
            'rows' => $assessments->map(function (Assessment $a) use ($by): array {
                $mine = $by[$a->id] ?? collect();

                return [
                    'movement' => $a->movement,
                    'all' => $mine->count(),
                    'done' => $mine->where('done', true)->count(),
                    'overdue' => $mine->filter(fn (OdpTask $t) => $t->state() === 'overdue')->count(),
                ];
            })->filter(fn (array $r) => $r['all'] > 0)
                // Most overdue first; then the least done.
                ->sort(fn (array $a, array $b) => [$b['overdue'], $a['done'] / $a['all']] <=> [$a['overdue'], $b['done'] / $b['all']])->values(),
        ];
    }
}
