<?php

namespace App\Http\Controllers;

use App\Actions\Movements\PauseFollowUp;
use App\Actions\Oha\AttachTaskEvidence;
use App\Actions\Oha\CarryOverTasks;
use App\Actions\Oha\CloseFollowUp;
use App\Actions\Oha\ConfirmTask;
use App\Actions\Oha\DropTask;
use App\Actions\Oha\RecordFollowUp;
use App\Actions\Oha\RecordOdpReview;
use App\Actions\Oha\ReportTaskProgress;
use App\Enums\TaskProgress;
use App\Models\Assessment;
use App\Models\Evidence;
use App\Models\MovementStatus;
use App\Models\OdpTask;
use App\Oha\FollowUpSchedule;
use App\Support\UploadLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Stage 2, ODP follow-up: a signed assessment's ODP tasks, where each stands, and
 * reporting and confirming their progress.
 */
class FollowUpController extends Controller
{
    public function show(Request $request, Assessment $assessment): View
    {
        Gate::authorize('viewFollowUp', $assessment);
        $user = $request->user();
        $assessment->load('movement.pausedBy');

        $tasks = $assessment->odpTasks()->with(['reporter', 'confirmer', 'evidence', 'carriedTo', 'carriedFrom.assessment'])->orderBy('sort_order')->orderBy('id')->get();
        $gate = Gate::forUser($user);

        return view('follow-up.show', [
            'assessment' => $assessment,
            // By strategic priority, in the ODP's order.
            'priorities' => $tasks->groupBy(fn (OdpTask $t) => $t->priority ?? 'Other tasks'),
            'counts' => [
                'total' => $tasks->count(),
                'done' => $tasks->where('done', true)->count(),
                'waiting' => $tasks->filter(fn (OdpTask $t) => $t->awaitsConfirmation())->count(),
                'overdue' => $tasks->filter(fn (OdpTask $t) => $t->state() === 'overdue')->count(),
                'blocked' => $tasks->where('progress', TaskProgress::Blocked)->count(),
            ],
            'canReport' => $tasks->isNotEmpty() && $gate->allows('report', $tasks->first()),
            'canConfirm' => $user->canAssess($assessment->movement),
            // The follow-up meetings: 3 a year from the signing, each with its record if held.
            'meetings' => FollowUpSchedule::for($assessment),
            'canRecord' => $gate->allows('recordFollowUp', $assessment),
            // Step 5: the overall review, and the next assessment unfinished tasks can be carried into.
            'review' => $assessment->odpReview()->with('recorder')->first(),
            'next' => $assessment->nextAssessment(),
            'canReview' => $gate->allows('reviewOdp', $assessment),
            'canCarry' => $gate->allows('carryOdpTasks', $assessment),
            'tasks' => $tasks,
            'evidence' => Evidence::query()->where('assessment_id', $assessment->id)->orderBy('title')->pluck('title', 'id')->all(),
            // The year's timeline: from the signing to the next assessment.
            'signedOn' => ($signed = $assessment->odp()->first()?->signature()->value('signed_at')) !== null ? Carbon::parse($signed) : null,
            'nextAssessmentOn' => ($nextOn = MovementStatus::query()->whereKey($assessment->movement_id)->value('next_assessment_on')) !== null ? Carbon::parse($nextOn) : null,
            // Closing the cycle: what still stands in the way, and who may close it.
            'closing' => CloseFollowUp::missing($assessment),
            'leftOpen' => CloseFollowUp::leftOpen($assessment),
            'canClose' => $gate->allows('closeFollowUp', $assessment),
            'canDrop' => $user->canAssess($assessment->movement) && ! $assessment->isFollowUpClosed(),
        ]);
    }

    /** Evidence added right on a task (a file or a link): into Resources, and onto the task. */
    public function evidence(Request $request, OdpTask $odpTask, AttachTaskEvidence $attach): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:file,link'],
            'file' => ['required_if:kind,file', ...UploadLimit::rule(required: false)],
            'url' => ['required_if:kind,link', 'nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:200'],
        ], ['file.required_if' => 'Choose the file to add.', 'url.required_if' => 'Paste the link to add.'] + UploadLimit::messages('file'));
        $file = $data['kind'] === 'file' ? $request->file('file') : null;

        $evidence = $attach->handle($odpTask, $request->user(),
            $file !== null ? (string) $file->getRealPath() : null, $file?->getClientOriginalName(),
            $data['kind'] === 'link' ? ($data['url'] ?? null) : null, $data['title'] ?? null);

        return $this->back($odpTask, "Evidence added: “{$evidence->title}”. It is in Resources too, backing this task.");
    }

    /** An assessor drops an unfinished task with a reason (or, with ?undo, restores it). */
    public function drop(Request $request, OdpTask $odpTask, DropTask $drop): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $undo = $request->boolean('undo');
        $task = $drop->handle($odpTask, $request->user(), $data['reason'] ?? null, $undo);

        return $this->back($task, $undo ? 'The task is back in the follow-up.' : 'Dropped from the follow-up. The reason is kept with it.');
    }

    /** An assessor or an Administrator closes the follow-up cycle (Stage 2 done). */
    public function close(Request $request, Assessment $assessment, CloseFollowUp $close): RedirectResponse
    {
        $close->handle($assessment, $request->user());

        return redirect()->route('follow-up.show', $assessment)
            ->with('status', 'The cycle is closed: the follow-up is kept as a record, and the movement is ready for its next assessment.');
    }

    public function progress(Request $request, OdpTask $odpTask, ReportTaskProgress $report): RedirectResponse
    {
        $data = $request->validate([
            'progress' => ['required', Rule::enum(TaskProgress::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'evidence_id' => ['nullable', 'integer'],
        ]);
        $task = $report->handle($odpTask, $request->user(), TaskProgress::from($data['progress']), $data['note'] ?? null, isset($data['evidence_id']) ? (int) $data['evidence_id'] : null);

        return $this->back($task, $task->awaitsConfirmation()
            ? 'Reported done. The assessors are asked to confirm it.'
            : 'Saved: '.$task->progress->label().($task->done ? ', confirmed.' : '.'));
    }

    public function confirm(Request $request, OdpTask $odpTask, ConfirmTask $confirm): RedirectResponse
    {
        $data = $request->validate([
            'confirmed' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $task = $confirm->handle($odpTask, $request->user(), (bool) $data['confirmed'], $data['reason'] ?? null);

        return $this->back($task, $task->done ? 'Confirmed done.' : 'Sent back as still in progress. The person who reported it is told why.');
    }

    /** An assessor records a follow-up meeting (or corrects one). */
    public function record(Request $request, Assessment $assessment, RecordFollowUp $record): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'integer', 'min:1'],
            'held_on' => ['required', 'date_format:Y-m-d'],
            'attendees' => ['nullable', 'string', 'max:1000'],
            'summary' => ['required', 'string', 'max:10000'],
            'updates' => ['array'],
            'updates.*' => ['nullable', 'string', 'max:2000'],
        ], ['summary.required' => 'Say what was discussed and agreed at the meeting.']);
        $meeting = $record->handle($assessment, $request->user(), (int) $data['number'], $data['held_on'], $data['attendees'] ?? null, $data['summary'], $data['updates'] ?? []);

        return redirect()->to(route('follow-up.show', $assessment).'#meeting-'.$meeting->number)
            ->with('status', "Follow-up meeting {$meeting->number} recorded. The movement and the other assessors are told.");
    }

    /** An assessor writes (or corrects) the overall review of the ODP. */
    public function review(Request $request, Assessment $assessment, RecordOdpReview $review): RedirectResponse
    {
        $data = $request->validate([
            'achieved' => ['required', 'string', 'max:10000'],
            'not_achieved' => ['required', 'string', 'max:10000'],
            'lessons' => ['nullable', 'string', 'max:10000'],
        ], ['achieved.required' => 'Say what was achieved.', 'not_achieved.required' => 'Say what was not achieved, and why.']);
        $review->handle($assessment, $request->user(), $data['achieved'], $data['not_achieved'], $data['lessons'] ?? null);

        return redirect()->to(route('follow-up.show', $assessment).'#review')->with('status', 'The ODP review is saved. The movement and the other assessors are told.');
    }

    /** An assessor carries chosen unfinished tasks into the movement's next assessment. */
    public function carry(Request $request, Assessment $assessment, CarryOverTasks $carry): RedirectResponse
    {
        $data = $request->validate(['tasks' => ['required', 'array'], 'tasks.*' => ['integer']], ['tasks.required' => 'Choose the unfinished tasks to carry over.']);
        $count = $carry->handle($assessment, $request->user(), array_map('intval', $data['tasks']));

        return redirect()->to(route('follow-up.show', $assessment).'#review')
            ->with('status', $count.' '.($count === 1 ? 'task' : 'tasks').' carried into the next assessment, where its assessors set new dates on Timelines.');
    }

    /** An Administrator pauses a movement's follow-up (with ?resume, lifts the pause). */
    public function pause(Request $request, Assessment $assessment, PauseFollowUp $pause): RedirectResponse
    {
        $movement = $assessment->movement;
        if ($request->boolean('resume')) {
            $pause->resume($movement, $request->user());

            return redirect()->route('follow-up.show', $assessment)->with('status', 'The pause is lifted. Follow-up and its reminders resume.');
        }
        $data = $request->validate([
            'until' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['reason.required' => 'Say why the follow-up is paused (for example, board elections).']);
        $pause->pause($movement, $request->user(), $data['until'], $data['reason']);

        return redirect()->route('follow-up.show', $assessment)->with('status', 'Follow-up paused. The movement and its assessors are told.');
    }

    private function back(OdpTask $task, string $status): RedirectResponse
    {
        return redirect()->to(route('follow-up.show', $task->assessment_id).'#task-'.$task->id)->with('status', $status);
    }
}
