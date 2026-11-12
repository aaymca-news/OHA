<?php

namespace App\Http\Controllers;

use App\Actions\Movements\PauseFollowUp;
use App\Actions\Oha\AttachTaskEvidence;
use App\Actions\Oha\CarryOverTasks;
use App\Actions\Oha\CloseFollowUp;
use App\Actions\Oha\DropTask;
use App\Actions\Oha\FollowUpWindows;
use App\Actions\Oha\NoteOnTask;
use App\Actions\Oha\RecordFollowUp;
use App\Actions\Oha\RecordOdpReview;
use App\Actions\Oha\ReviseOdp;
use App\Enums\DocumentPurpose;
use App\Enums\TaskProgress;
use App\Models\Assessment;
use App\Models\Evidence;
use App\Models\OdpTask;
use App\Oha\FollowUpSchedule;
use App\Support\UploadLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Stage 2, ODP follow-up: a closed assessment's ODP tasks, where each stands, and
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
                'blocked' => $tasks->filter(fn (OdpTask $t) => ! $t->done && $t->progress === TaskProgress::Blocked)->count(),
            ],
            // Between the follow-ups: a note or evidence on a task (the General Secretary, the assessors).
            'canNote' => $tasks->isNotEmpty() && $gate->allows('note', $tasks->first()),
            // The three follow-ups, each with its date, state and meeting record.
            'meetings' => FollowUpSchedule::for($assessment),
            'canRecord' => $gate->allows('recordFollowUp', $assessment),
            // Each follow-up's window: closing it (assessors, Administrators), opening early or reopening (Administrators).
            'canRun' => $gate->allows('runFollowUps', $assessment),
            'canManageWindows' => $gate->allows('manageFollowUps', $assessment),
            // The ODP revised during the follow-ups (newest first), and who may revise it now.
            'revisions' => ($odp = $assessment->odp()->first()) !== null
                ? $odp->documents()->where('purpose', DocumentPurpose::Revision)->with('creator')->latest('id')->get() : collect(),
            'canRevise' => $odp !== null && $gate->allows('revise', $odp),
            // How many documents each follow-up counts (or will), by its number.
            'evidenceCounts' => Evidence::query()->where('assessment_id', $assessment->id)->whereNotNull('counted_in')
                ->selectRaw('counted_in, count(*) AS n')->groupBy('counted_in')->pluck('n', 'counted_in')->all(),
            // Step 5: the overall review, and the next assessment unfinished tasks can be carried into.
            'review' => $assessment->odpReview()->with('recorder')->first(),
            'next' => $assessment->nextAssessment(),
            'canReview' => $gate->allows('reviewOdp', $assessment),
            'canCarry' => $gate->allows('carryOdpTasks', $assessment),
            'tasks' => $tasks,
            'evidence' => Evidence::query()->where('assessment_id', $assessment->id)->orderBy('title')->pluck('title', 'id')->all(),
            // The year's timeline: from the closing (Baseline 1) to the next assessment.
            'closedOn' => $assessment->closed_at,

            // Closing the cycle: what still stands in the way, and who may close it.
            'closing' => CloseFollowUp::missing($assessment),
            'leftOpen' => CloseFollowUp::leftOpen($assessment),
            'canClose' => $gate->allows('closeFollowUp', $assessment),
            // Closing before the three follow-ups are closed: an Administrator only, with a reason.
            'early' => CloseFollowUp::isEarly($assessment),
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

    /** An assessor or an Administrator closes the follow-up cycle (Stage 2 done); early, only an Administrator, with a reason. */
    public function close(Request $request, Assessment $assessment, CloseFollowUp $close): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $close->handle($assessment, $request->user(), $data['reason'] ?? null);

        return redirect()->route('follow-up.show', $assessment)
            ->with('status', 'The cycle is closed: the follow-up is kept as a record, and the movement is ready for its next assessment.');
    }

    /** Between the follow-ups: a note on a task, or evidence from the library linked to it. */
    public function note(Request $request, OdpTask $odpTask, NoteOnTask $note): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'evidence_id' => ['nullable', 'integer'],
        ]);
        $task = $note->handle($odpTask, $request->user(), $data['note'] ?? null, isset($data['evidence_id']) ? (int) $data['evidence_id'] : null);

        return $this->back($task, 'Saved. Where the task stands is set at the next follow-up.');
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
            'statuses' => ['array'],
            'statuses.*' => [Rule::enum(TaskProgress::class)],
        ], ['summary.required' => 'Say what was discussed and agreed at the meeting.']);
        $meeting = $record->handle($assessment, $request->user(), (int) $data['number'], $data['held_on'], $data['attendees'] ?? null, $data['summary'], $data['updates'] ?? [], $data['statuses'] ?? []);

        return redirect()->to(route('follow-up.show', $assessment).'#meeting-'.$meeting->number)
            ->with('status', "Follow-up meeting {$meeting->number} recorded. The movement and the other assessors are told.");
    }

    /** Its assessors or an Administrator set (or change, or clear) a follow-up's date, as agreed with the movement. */
    public function plan(Request $request, Assessment $assessment, int $number, FollowUpWindows $windows): RedirectResponse
    {
        $data = $request->validate(['due_on' => ['nullable', 'date_format:Y-m-d']], ['due_on.date_format' => 'Choose a real date for the follow-up.']);
        $windows->plan($assessment, $request->user(), $number, $data['due_on'] ?? null);

        return redirect()->to(route('follow-up.show', $assessment).'#meeting-'.$number)
            ->with('status', isset($data['due_on']) ? "Follow-up {$number}’s date is saved. The General Secretary and the assessors are told." : "Follow-up {$number}’s date is cleared.");
    }

    /** An assessor or an Administrator closes (and so locks) one follow-up, once its meeting is recorded, and may date the next. */
    public function lock(Request $request, Assessment $assessment, int $number, FollowUpWindows $windows): RedirectResponse
    {
        $data = $request->validate(['next_due_on' => ['nullable', 'date_format:Y-m-d']], ['next_due_on.date_format' => 'Choose a real date for the next follow-up.']);
        $windows->close($assessment, $request->user(), $number, $data['next_due_on'] ?? null);

        return redirect()->to(route('follow-up.show', $assessment).'#meeting-'.$number)
            ->with('status', "Follow-up {$number} is closed. What it counted is locked; the movement and the other assessors are told.");
    }

    /** Its assessors or an Administrator upload the revised ODP while a follow-up is open. */
    public function reviseOdp(Request $request, Assessment $assessment, ReviseOdp $revise): RedirectResponse
    {
        $data = $request->validate([
            'odp' => UploadLimit::rule(),
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['odp.required' => 'Choose the revised ODP file.'] + UploadLimit::messages('odp'));
        $file = $request->file('odp');
        $revision = $revise->upload($assessment->odp()->firstOrFail(), (string) $file->getRealPath(), $file->getClientOriginalName(), $request->user(), $data['note'] ?? null);

        return redirect()->to(route('follow-up.show', $assessment).'#odp-revisions')
            ->with('status', 'The revised ODP is saved for follow-up '.$revision->follow_up_number.'. Its tasks are matched against the plan; what changed is listed below.');
    }

    /** Its assessors or an Administrator open the next follow-up. */
    public function open(Request $request, Assessment $assessment, FollowUpWindows $windows): RedirectResponse
    {
        $window = $windows->open($assessment, $request->user());

        return redirect()->to(route('follow-up.show', $assessment).'#meeting-'.$window->number)
            ->with('status', "Follow-up {$window->number} is open. The General Secretary and the assessors are told.");
    }

    /** An Administrator reopens the last follow-up closed, with a reason. */
    public function reopen(Request $request, Assessment $assessment, int $number, FollowUpWindows $windows): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => 'Say why the follow-up is reopened.']);
        $windows->reopen($assessment, $request->user(), $number, $data['reason']);

        return redirect()->to(route('follow-up.show', $assessment).'#meeting-'.$number)
            ->with('status', "Follow-up {$number} is reopened. Correct it, then close it again.");
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
