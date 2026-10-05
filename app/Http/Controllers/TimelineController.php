<?php

namespace App\Http\Controllers;

use App\Actions\Oha\EditTimeline;
use App\Models\Assessment;
use App\Models\AssessmentMilestone;
use App\Models\TimelineItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TimelineController extends Controller
{
    /** Every ongoing assessment's deadlines, on one page. */
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isAssessor(), 403);

        $assessments = Assessment::query()
            ->with(['movement', 'workItem'])
            ->whereHas('workItem')
            ->orderBy('opened_at')->orderBy('id')
            ->get();

        $milestones = AssessmentMilestone::query()->whereIn('assessment_id', $assessments->pluck('id'))->orderBy('sort_order')->get()->groupBy('assessment_id');
        $steps = TimelineItem::query()->whereIn('assessment_id', $assessments->pluck('id'))->whereNull('gate_id')->orderBy('due_on')->get()->groupBy('assessment_id');

        $dates = $milestones->flatten()->pluck('due_on')->merge($milestones->flatten()->pluck('done_at')->filter())->push(now());
        $start = $dates->min()?->copy()->startOfMonth() ?? now()->startOfMonth();
        $end = $dates->max()?->copy()->endOfMonth() ?? now()->endOfMonth();

        return view('timelines', [
            'assessments' => $assessments,
            'milestones' => $milestones,
            'steps' => $steps,
            'start' => $start,
            'end' => $end,
            'missed' => $milestones->flatten()->where('overdue', true)->count(),
            'dueSoon' => $milestones->flatten()->filter(fn ($m) => $m->done_at === null && ! $m->overdue && $m->due_on->lte(now()->addDays(7)))->count(),
            'view' => $request->query('view') === 'table' ? 'table' : 'chart',
        ]);
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
        return redirect()->route('assessments.show', ['assessment' => $assessment, 'tab' => 'timeline'])->with('status', $status);
    }
}
