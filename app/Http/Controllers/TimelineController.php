<?php

namespace App\Http\Controllers;

use App\Actions\Oha\EditTimeline;
use App\Http\Controllers\Concerns\BacksToAssessment;
use App\Models\Assessment;
use App\Models\AssessmentMilestone;
use App\Models\OdpTask;
use App\Models\TimelineItem;
use App\Oha\FollowUpSchedule;
use App\Queries\OdpProgress;
use App\Support\OdpTimeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TimelineController extends Controller
{
    use BacksToAssessment;

    /**
     * Three views on one page (decided 12 Nov 2026): the follow-ups of each movement in
     * follow-up (their agreed dates, open, closed, overdue), the ODP tasks (where each stands,
     * as set at the last follow-up: tasks have no deadlines of their own), and the deadlines
     * of the assessments under way (Stage 1). The Administrators see every movement's; staff
     * see those they assess.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        Gate::authorize('viewTimelines');
        $mine = $user->visibleMovementIds();

        return match ($request->query('tab')) {
            'gates' => $this->gates($request, $mine),
            'odp' => $this->odpTasks($request, $mine),
            default => $this->followUps($request, $mine),
        };
    }

    /**
     * Each movement in follow-up, with its three follow-ups. Filtered to those overdue,
     * open now, or whose next follow-up has no date yet.
     *
     * @param  list<int>|null  $mine  the movements this person assesses; null for every movement
     */
    private function followUps(Request $request, ?array $mine): View
    {
        $rows = Assessment::query()->with('movement')
            ->whereNotNull('closed_at')->whereNull('follow_up_closed_at')
            ->when($mine !== null, fn ($q) => $q->whereIn('movement_id', $mine))
            ->get()->sortBy(fn (Assessment $a) => $a->movement->name)
            ->map(function (Assessment $a) {
                $schedule = FollowUpSchedule::for($a);
                $next = collect($schedule)->first(fn (array $f) => in_array($f['state'], [FollowUpSchedule::OPEN, FollowUpSchedule::UPCOMING], true));
                $tasks = $a->odpTasks()->get();

                return [
                    'assessment' => $a,
                    'schedule' => $schedule,
                    'next' => $next,
                    'summary' => OdpProgress::summary($tasks),
                    'flags' => array_values(array_filter([
                        ($next['overdue'] ?? false) ? 'overdue' : null,
                        ($next['state'] ?? null) === FollowUpSchedule::OPEN ? 'open' : null,
                        $next !== null && $next['due_on'] === null ? 'unset' : null,
                    ])),
                ];
            })->values();

        $state = in_array($request->query('state'), ['overdue', 'open', 'unset'], true) ? (string) $request->query('state') : null;

        return view('timelines', [
            'tab' => 'follow-ups',
            'rows' => $state === null ? $rows : $rows->filter(fn (array $r) => in_array($state, $r['flags'], true))->values(),
            'counts' => collect(['overdue', 'open', 'unset'])->mapWithKeys(fn (string $k) => [$k => $rows->filter(fn (array $r) => in_array($k, $r['flags'], true))->count()])->all() + ['all' => $rows->count()],
            'state' => $state,
        ]);
    }

    /**
     * @param  list<int>|null  $mine  the movements this person assesses; null for every movement
     */
    private function odpTasks(Request $request, ?array $mine): View
    {
        $assessments = OdpTimeline::assessments($mine);

        $tasks = OdpTask::query()->whereIn('assessment_id', $assessments->pluck('id'))
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('assessment_id');
        $all = $tasks->flatten();
        $counts = $all->countBy(fn (OdpTask $t) => $t->followUpStatus()['key']);

        $state = in_array($request->query('state'), ['blocked', 'progress', 'done'], true) ? $request->query('state') : null;
        $movement = $request->query('movement');

        return view('timelines', [
            'tab' => 'odp',
            'assessments' => $assessments->filter(fn (Assessment $a) => $movement === null || $a->movement->slug === $movement)->values(),
            'movements' => $assessments->pluck('movement'),
            'tasks' => $tasks->map(fn ($group) => $group->filter(fn (OdpTask $t) => $state === null || $t->followUpStatus()['key'] === $state)->values()),
            'totals' => $tasks->map(fn ($group) => ['all' => $group->count(), 'done' => $group->where('done', true)->count()]),
            'counts' => $counts,
            'state' => $state,
            'movement' => $movement,
            'canEdit' => $assessments->mapWithKeys(fn (Assessment $a) => [$a->id => Gate::forUser($request->user())->allows('editTimeline', $a)]),
        ]);
    }

    /**
     * @param  list<int>|null  $mine  the movements this person assesses; null for every movement
     */
    private function gates(Request $request, ?array $mine): View
    {
        $assessments = Assessment::query()
            ->with(['movement', 'workItem'])
            ->underWay()
            ->when($mine !== null, fn ($q) => $q->whereIn('movement_id', $mine))
            ->orderBy('opened_at')->orderBy('id')
            ->get();

        $milestones = AssessmentMilestone::query()->whereIn('assessment_id', $assessments->pluck('id'))->orderBy('sort_order')->get()->groupBy('assessment_id');
        $steps = TimelineItem::query()->whereIn('assessment_id', $assessments->pluck('id'))->whereNull('gate_id')->orderBy('due_on')->get()->groupBy('assessment_id');

        $dates = $milestones->flatten()->pluck('due_on')->merge($milestones->flatten()->pluck('done_at')->filter())->push(now());
        $start = $dates->min()?->copy()->startOfMonth() ?? now()->startOfMonth();
        $end = $dates->max()?->copy()->endOfMonth() ?? now()->endOfMonth();

        return view('timelines', [
            'tab' => 'gates',
            'assessments' => $assessments,
            'milestones' => $milestones,
            'steps' => $steps,
            'start' => $start,
            'end' => $end,
            'missed' => $milestones->flatten()->where('overdue', true)->count(),
            'dueSoon' => $milestones->flatten()->filter(fn ($m) => $m->done_at === null && ! $m->overdue && $m->due_on->lte(now()->addDays(config('oha.due_soon_days.deadlines'))))->count(),
            'view' => $request->query('view') === 'table' ? 'table' : 'chart',
        ]);
    }

    public function addTask(Request $request, Assessment $assessment, EditTimeline $timeline): RedirectResponse
    {
        $timeline->addTask($assessment, $this->task($request), $request->user());

        return back()->with('status', 'Task added.');
    }

    public function updateTask(Request $request, OdpTask $odpTask, EditTimeline $timeline): RedirectResponse
    {
        $timeline->updateTask($odpTask, $this->task($request), $request->user());

        return back()->with('status', 'Task saved.');
    }

    public function removeTask(Request $request, OdpTask $odpTask, EditTimeline $timeline): RedirectResponse
    {
        $timeline->removeTask($odpTask, $request->user());

        return back()->with('status', 'Task removed.');
    }

    /**
     * @return array{task: string, ref?: string|null, responsible?: string|null}
     */
    private function task(Request $request): array
    {
        return $request->validate([
            'task' => ['required', 'string', 'max:4000'],
            'ref' => ['nullable', 'string', 'max:16'],
            'responsible' => ['nullable', 'string', 'max:500'],
        ], ['task.required' => 'Say what the task is.']);
    }

    public function setGate(Request $request, Assessment $assessment, EditTimeline $timeline): RedirectResponse
    {
        $data = $request->validate(['gate' => ['required', 'exists:gates,code'], 'due_on' => ['nullable', 'date_format:Y-m-d']]);
        $timeline->setGateDeadline($assessment, $data['gate'], $data['due_on'] ?? null, $request->user());

        return $this->back($assessment, 'Deadline saved.');
    }

    public function addStep(Request $request, Assessment $assessment, EditTimeline $timeline): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:255'], 'due_on' => ['nullable', 'date_format:Y-m-d']]);
        $timeline->addStep($assessment, $data['label'], $data['due_on'] ?? null, $request->user());

        return $this->back($assessment, 'Step added.');
    }

    public function updateStep(Request $request, TimelineItem $step, EditTimeline $timeline): RedirectResponse
    {
        $data = $request->validate(['due_on' => ['nullable', 'date_format:Y-m-d'], 'done' => ['boolean']]);
        $timeline->updateStep($step, $data['due_on'] ?? null, (bool) ($data['done'] ?? false), $request->user());

        return $this->back($step->assessment, 'Step updated.');
    }

    public function removeStep(Request $request, TimelineItem $step, EditTimeline $timeline): RedirectResponse
    {
        $assessment = $step->assessment;
        $timeline->removeStep($step, $request->user());

        return $this->back($assessment, 'Step removed.');
    }

    private function back(Assessment $assessment, string $status): RedirectResponse
    {
        return $this->toTab($assessment, $status, 'timeline');
    }
}
