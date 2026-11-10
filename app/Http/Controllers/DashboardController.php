<?php

namespace App\Http\Controllers;

use App\Enums\BandCode;
use App\Enums\Role;
use App\Enums\Stage;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\OdpTask;
use App\Models\User;
use App\Queries\AllianceInsights;
use App\Queries\AllianceStats;
use App\Queries\MovementSnapshot;
use App\Queries\MyWork;
use App\Queries\OdpActivity;
use App\Queries\OdpProgress;
use App\Support\MovementCycle;
use App\Support\OdpTimeline;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Two dashboards. AAYMCA (staff and Administrators, all alike): the region's analytics —
 * how healthy the movements are, where Stage 1 stands, and how the ODPs are being
 * carried out — with the detail by category further down. Their own work stays in My
 * Work, never on the dashboard. A National Movement's Board Chairperson and National
 * General Secretary: their own movement at a glance, with their one next step first.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, MyWork $myWork, AllianceStats $stats, AllianceInsights $insights, OdpActivity $odps): View
    {
        $user = $request->user();

        if (Gate::forUser($user)->denies('viewAllianceStats')) {
            $work = collect($myWork->for($user))
                ->flatMap(fn (array $group) => collect($group['items'])->where('actionable', true))
                ->take(6);

            return $this->movement($user, $work);
        }

        $statuses = MovementStatus::query()->with(['band', 'movement.zone'])->orderByRaw('pct DESC NULLS LAST')->orderBy('name')->get();

        return view('dashboard.secretariat', [
            'headline' => $stats->headline(),
            'bands' => $stats->bandDistribution(),
            'weakest' => $stats->weakestCategories(),
            'due' => $stats->reassessmentDue(),
            'underWay' => Movement::withAssessmentUnderWay(),
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

        $snapshot = MovementSnapshot::of($status);

        // The assessment under way, if any: where it stands, gate by gate.
        $current = $movement->assessments()->underWay()->with('workItem')->latest('id')->first();

        // The ODP's tasks, once the ODP itself is theirs to see.
        $odpAssessment = OdpTimeline::assessments([$movement->id])->first();
        $odpArtefact = $odpAssessment?->odp()->first();
        // Its tasks were read when the ODP was saved (and oha:read-odp-tasks catches up any missed).
        $showOdp = $odpArtefact !== null && $gate->allows('view', $odpArtefact);
        $tasks = $showOdp ? OdpTask::query()->where('assessment_id', $odpAssessment->id)->orderBy('sort_order')->orderBy('id')->get() : collect();
        $lastDone = $current === null ? $movement->lastAssessmentDone() : null;
        $odp = $showOdp ? OdpProgress::summary($tasks) : null;
        $canFollowUp = $showOdp && $gate->allows('viewFollowUp', $odpAssessment);
        $canReport = $canFollowUp && $tasks->isNotEmpty() && $gate->allows('report', $tasks->first());

        return view('dashboard.board', [
            'movement' => $movement,
            'status' => $status,
            'work' => $work,
            'latest' => $snapshot->latest,
            'rows' => $snapshot->rows,
            'profile' => $snapshot->profile,
            'current' => $current,
            'lastDone' => $lastDone,
            // The cycle, gate by gate and on into the follow-up year.
            'cycle' => MovementCycle::steps($current ?? $lastDone, $status->next_assessment_on),
            'cycleOf' => $current ?? $lastDone,
            'action' => $this->action($user, $work, $current, $showOdp ? $odpAssessment : null, $tasks, $odp, $canReport),
            'odpAssessment' => $showOdp ? $odpAssessment : null,
            'odp' => $odp,
            'priorities' => $showOdp ? OdpProgress::byPriority($tasks) : [],
            'canFollowUp' => $canFollowUp,
            'canReport' => $canReport,
            'tasks' => $tasks,
            'assessors' => $movement->activeAssessors()->get(),
            'assessments' => Assessment::query()->with('score.band')->where('movement_id', $movement->id)->latest('assessed_on')->latest('id')->get()
                ->filter(fn (Assessment $a) => $gate->allows('view', $a)),
        ]);
    }

    /**
     * The one thing waiting on this person, for the slim banner at the top of the
     * movement's dashboard; null when nothing is (the page then opens onto its figures).
     * The Board Chairperson: an ODP to sign (in My Work). The General Secretary: ODP
     * tasks overdue, or sent back by an assessor, to report on. Nothing else: what is
     * under way or on track is already in the figures and the cycle.
     *
     * @param  Collection<int, mixed>  $work
     * @param  Collection<int, OdpTask>  $tasks
     * @param  array{total: int, done: int, waiting: int, overdue: int, blocked: int, pct: int}|null  $odp
     * @return array{title: string, href: string, button: string}|null
     */
    private function action(User $user, Collection $work, ?Assessment $current, ?Assessment $odpAssessment, Collection $tasks, ?array $odp, bool $canReport): ?array
    {
        if ($work->isNotEmpty()) {
            $toSign = $current !== null && $user->role === Role::Board && Stage::of($current) === Stage::Sign;
            $n = $work->count();

            return $toSign
                ? ['title' => 'Your '.$current->period_label.' ODP is ready for your signature', 'href' => route('my-work'), 'button' => 'Review and sign']
                : ['title' => $n.' '.Str::plural('item', $n).' '.($n === 1 ? 'needs' : 'need').' you in My Work', 'href' => route('my-work'), 'button' => 'Open My Work'];
        }

        if ($canReport && $odpAssessment !== null && $odp !== null) {
            $overdue = $odp['overdue'];
            $returned = $tasks->filter(fn (OdpTask $t) => ! $t->isSettled() && $t->returned_reason !== null)->count();
            $tasksOf = fn (int $n) => $n.' ODP '.Str::plural('task', $n);
            $title = match (true) {
                $overdue > 0 && $returned > 0 => $tasksOf($overdue).' '.($overdue === 1 ? 'is' : 'are').' overdue and '.$returned.' sent back: report their progress',
                $overdue > 0 => $tasksOf($overdue).' '.($overdue === 1 ? 'is' : 'are').' overdue: report '.($overdue === 1 ? 'its' : 'their').' progress',
                $returned > 0 => $tasksOf($returned).' '.($returned === 1 ? 'was' : 'were').' sent back by your assessors: update '.($returned === 1 ? 'it' : 'them'),
                default => null,
            };

            return $title !== null ? ['title' => $title, 'href' => route('follow-up.show', $odpAssessment), 'button' => 'Report progress'] : null;
        }

        return null;
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
                    'at_risk' => $rows->whereIn('band_code', BandCode::NEEDING_SUPPORT)->count(),
                ];
            })->sortBy('zone')->values();
    }

    /**
     * How the ODPs are being carried out: every task's state across the region, and
     * per movement, how many of its tasks are done and how many are overdue.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @return array{counts: Collection<array-key, int>, total: int, rows: list<array<string, mixed>>}
     */
    private function odpProgress(Collection $assessments): array
    {
        $tasks = OdpTask::query()->whereIn('assessment_id', $assessments->pluck('id'))->get();

        return [
            'counts' => $tasks->countBy(fn (OdpTask $t) => $t->state()),
            'total' => $tasks->count(),
            // Each movement side by side, falling behind first (App\Queries\OdpProgress).
            'rows' => OdpProgress::alliance($assessments),
        ];
    }
}
