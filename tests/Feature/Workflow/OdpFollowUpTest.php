<?php

use App\Actions\Evidence\UploadEvidence;
use App\Actions\Oha\FollowUpWindows;
use App\Actions\Oha\NoteOnTask;
use App\Actions\Oha\RecordFollowUp;
use App\Enums\TaskProgress;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\AuditEvent;
use App\Models\OdpTask;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\FollowUpSchedule;
use App\Support\OdpTimeline;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Stage 2, ODP follow-up (decided 12 Nov 2026): once the assessment is closed, the
 * movement's General Secretary and its assessors add notes and evidence on the tasks
 * between the follow-ups; where every task stands is set together at each follow-up
 * meeting, by the assessors. Tasks have no deadlines of their own.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda']);
    $this->assessment = $this->j->assessmentAt('closed');
    $this->task = OdpTask::query()->create([
        'assessment_id' => $this->assessment->id, 'source' => OdpTask::TYPED, 'ref' => '1.1',
        'priority' => 'FINANCIAL STABILITY', 'task' => 'Approve a resource mobilisation plan',
    ]);
    // Follow-up 1's meeting, setting where the tasks stand.
    $this->meeting = function (array $statuses, string $summary = 'Tasks reviewed.'): void {
        if ((FollowUpSchedule::next($this->assessment)['state'] ?? null) === FollowUpSchedule::UPCOMING) {
            $this->j->openFollowUp($this->assessment);
        }
        app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(), null, $summary, statuses: $statuses);
    };
});

it('lets the General Secretary add a note and evidence between the follow-ups, and sets where it stands at the meeting', function () {
    $evidence = app(UploadEvidence::class)->handle($this->ngs, $this->assessment, null, null, 'Resource mobilisation plan', url: 'https://example.org/plan');

    $task = app(NoteOnTask::class)->handle($this->task, $this->ngs, 'Approved by the board in June.', $evidence->id);
    expect($task->progress)->toBe(TaskProgress::NotStarted)
        ->and($task->done)->toBeFalse()
        ->and($task->progress_note)->toBe('Approved by the board in June.')
        ->and($task->evidence_id)->toBe($evidence->id)
        ->and(AuditEvent::query()->where('action', 'odp_task.noted')->count())->toBe(1);
    Notification::assertSentTo($this->j->assessor, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'A note on an ODP task'));

    // At the meeting, Done: it counts from then, marked done by the assessor.
    ($this->meeting)([$this->task->id => TaskProgress::Done->value]);
    $task->refresh();
    expect($task->done)->toBeTrue()->and($task->confirmed_by)->toBe($this->j->assessor->id)
        ->and($task->followUpStatus()['label'])->toBe('Done');
});

it('needs a note or evidence, from the movement or its assessors only', function () {
    expect(fn () => app(NoteOnTask::class)->handle($this->task, $this->ngs, ' '))->toThrow(WorkflowRuleBroken::class, 'Write a note')
        ->and(fn () => app(NoteOnTask::class)->handle($this->task, $this->j->chair, 'A note.'))->toThrow(WorkflowRuleBroken::class)
        ->and(fn () => app(NoteOnTask::class)->handle($this->task, $this->j->otherStaff, 'A note.'))->toThrow(WorkflowRuleBroken::class)
        ->and(app(NoteOnTask::class)->handle($this->task, $this->j->assessor, 'Seen the draft.')->progress_note)->toBe('Seen the draft.');
});

it('keeps follow-up to the General Secretary and the assessors, and only after closing', function () {
    // The page: the General Secretary adds notes; the Board Chairperson and other staff are kept out.
    $page = route('follow-up.show', $this->assessment);
    $this->actingAs($this->ngs)->get($page)->assertOk()->assertSee('Approve a resource mobilisation plan')->assertSee('Save the note');
    $this->actingAs($this->j->chair)->get($page)->assertForbidden();
    $this->actingAs($this->j->otherStaff)->get($page)->assertForbidden();

    // Before closing there is no follow-up.
    $open = Journey::open($this->j->assessor, $this->j->zambia, 'Mar 2028', now());
    $this->actingAs($this->j->assessor)->get(route('follow-up.show', $open))->assertForbidden();
});

it('adds a note through the page, which the assessors see', function () {
    $this->actingAs($this->ngs)->post(route('follow-up.note', $this->task), ['note' => 'Done in June.'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->j->assessor)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee('Mwila Banda noted: “Done in June.”', false)->assertSee('Not started');
});

it('keeps each task’s progress when a newer ODP version is read', function () {
    $task = OdpTask::query()->create([
        'assessment_id' => $this->assessment->id, 'source' => OdpTask::FROM_ODP, 'ref' => '2.1', 'priority' => 'GOVERNANCE', 'task' => 'Train the board',
    ]);
    ($this->meeting)([$task->id => TaskProgress::Done->value]);

    // A newer version of the same task, as read from the ODP (marked not done in the sheet).
    $replace = new ReflectionMethod(OdpTimeline::class, 'replace');
    $replace->invoke(null, $this->assessment, $this->j->odp($this->assessment)->versions()->latest('id')->firstOrFail(), [
        ['ref' => '2.1', 'priority' => 'GOVERNANCE', 'task' => 'Train the board', 'deadline_text' => null, 'due_on' => null, 'date_guessed' => false, 'responsible' => null, 'done' => false],
    ]);

    $after = OdpTask::query()->where('assessment_id', $this->assessment->id)->where('ref', '2.1')->firstOrFail();
    expect($after->progress)->toBe(TaskProgress::Done)->and($after->done)->toBeTrue();
});

it('plans three follow-ups once the assessment is closed, and records a meeting with every task as it stood', function () {
    $schedule = FollowUpSchedule::for($this->assessment);

    expect($schedule)->toHaveCount(3)
        ->and(collect($schedule)->pluck('due_on')->filter()->all())->toBe([]);
    $this->j->openFollowUp($this->assessment);
    $meeting = app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(),
        'Mwila Banda, Aminata Diallo', 'Reviewed the financial priorities. The plan goes to the board in March.',
        [$this->task->id => 'Draft shared with the finance committee.'], [$this->task->id => TaskProgress::InProgress->value]);

    expect($meeting->tasks)->toHaveCount(1)
        ->and($meeting->tasks[0]->progress)->toBe(TaskProgress::InProgress)
        ->and($meeting->tasks[0]->update)->toBe('Draft shared with the finance committee.');
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'Follow-up meeting 1'));
    // The Board Chairperson does not follow the follow-up (decided 12 Nov 2026).
    Notification::assertNotSentTo($this->j->chair, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'Follow-up meeting 1'));

    // Recording it again corrects it, rather than adding a second.
    app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(), null, 'Corrected summary.');
    expect($this->assessment->followUps()->count())->toBe(1)
        ->and($this->assessment->followUps()->first()->summary)->toBe('Corrected summary.');

    // The snapshot stays as it was when the task moves on at the next follow-up.
    app(FollowUpWindows::class)->close($this->assessment, $this->j->assessor, 1);
    $this->j->openFollowUp($this->assessment);
    app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 2, today()->toDateString(), null, 'Done now.', statuses: [$this->task->id => TaskProgress::Done->value]);
    expect($this->assessment->followUps()->where('number', 1)->first()->tasks()->first()->progress)->toBe(TaskProgress::InProgress)
        ->and($this->task->refresh()->done)->toBeTrue();
});

it('lets only the assessors record a meeting, once held, and only for a planned one', function () {
    $record = fn (User $by, int $n = 1, ?string $on = null) => app(RecordFollowUp::class)
        ->handle($this->assessment, $by, $n, $on ?? today()->toDateString(), null, 'Summary.');

    expect(fn () => $record($this->ngs))->toThrow(WorkflowRuleBroken::class)
        ->and(fn () => $record($this->j->assessor, 4))->toThrow(WorkflowRuleBroken::class)
        // In order: not follow-up 2 while follow-up 1 is not closed.
        ->and(fn () => $record($this->j->assessor, 2))->toThrow(WorkflowRuleBroken::class, 'Close follow-up 1 first')
        ->and(fn () => $record($this->j->assessor, 1, today()->addDay()->toDateString()))->toThrow(WorkflowRuleBroken::class);
});

it('records a meeting through the page, and shows it to the movement', function () {
    $this->j->openFollowUp($this->assessment);
    $this->actingAs($this->j->assessor)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee('Follow-ups')->assertSee('Record the meeting');

    $this->actingAs($this->j->assessor)->post(route('follow-up.meetings.store', $this->assessment), [
        'number' => 1, 'held_on' => today()->toDateString(), 'attendees' => 'Mwila Banda', 'summary' => 'All priorities reviewed.',
        'updates' => [$this->task->id => 'On track.'],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->actingAs($this->ngs)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee('Meeting held '.today()->asDay())->assertSee('All priorities reviewed.')->assertSee('On track.')
        ->assertDontSee('Record the meeting')->assertDontSee('Close follow-up 1');
});
