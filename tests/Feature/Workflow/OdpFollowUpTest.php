<?php

use App\Actions\Evidence\UploadEvidence;
use App\Actions\Oha\ConfirmTask;
use App\Actions\Oha\RecordFollowUp;
use App\Actions\Oha\ReportTaskProgress;
use App\Enums\TaskProgress;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\AuditEvent;
use App\Models\OdpTask;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\FollowUpSchedule;
use App\Queries\MyWork;
use App\Support\OdpTimeline;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Stage 2, ODP follow-up: once the ODP is signed, the movement's General Secretary and
 * its assessors report each task's progress; a Done from the General Secretary counts
 * once an assessor confirms it.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda']);
    $this->assessment = $this->j->assessmentAt('odp_signed');
    $this->task = OdpTask::query()->create([
        'assessment_id' => $this->assessment->id, 'source' => OdpTask::TYPED, 'ref' => '1.1',
        'priority' => 'FINANCIAL STABILITY', 'task' => 'Approve a resource mobilisation plan', 'due_on' => now()->addMonths(2)->toDateString(),
    ]);
});

it('lets the General Secretary report progress, and an assessor confirm it done', function () {
    $evidence = app(UploadEvidence::class)->handle($this->ngs, $this->assessment, null, null, 'Resource mobilisation plan', url: 'https://example.org/plan');

    $task = app(ReportTaskProgress::class)->handle($this->task, $this->ngs, TaskProgress::Done, 'Approved by the board in June.', $evidence->id);
    expect($task->progress)->toBe(TaskProgress::Done)
        ->and($task->done)->toBeFalse()
        ->and($task->awaitsConfirmation())->toBeTrue()
        ->and($task->evidence_id)->toBe($evidence->id);
    Notification::assertSentTo($this->j->assessor, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'Please confirm'));

    // The assessor sees it waiting in My Work, and it counts on the badge.
    $done = collect(app(MyWork::class)->for($this->j->assessor))->keyBy('key')['done']['items'];
    expect($done[0]['waiting'])->toBe(1)->and($done[0]['actionable'])->toBeTrue();

    $task = app(ConfirmTask::class)->handle($task, $this->j->assessor, true);
    expect($task->done)->toBeTrue()->and($task->confirmed_by)->toBe($this->j->assessor->id);
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'Confirmed done'));
    expect(AuditEvent::query()->whereIn('action', ['odp_task.progress', 'odp_task.confirmed'])->count())->toBe(2);
});

it('sends a Done back with a reason, and needs the reason', function () {
    $task = app(ReportTaskProgress::class)->handle($this->task, $this->ngs, TaskProgress::Done);

    expect(fn () => app(ConfirmTask::class)->handle($task, $this->j->assessor, false))->toThrow(WorkflowRuleBroken::class);

    $task = app(ConfirmTask::class)->handle($task, $this->j->assessor, false, 'The plan is not yet approved by the board.');
    expect($task->progress)->toBe(TaskProgress::InProgress)->and($task->done)->toBeFalse()
        ->and($task->returned_reason)->toBe('The plan is not yet approved by the board.');
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'Not confirmed yet'));
});

it('counts a Done from an assessor at once', function () {
    $task = app(ReportTaskProgress::class)->handle($this->task, $this->j->assessor, TaskProgress::Done, 'Seen at the follow-up meeting.');

    expect($task->done)->toBeTrue()->and($task->confirmed_by)->toBe($this->j->assessor->id);
});

it('keeps follow-up to the movement and its assessors, and only after signing', function () {
    $chair = $this->j->chair;
    expect(fn () => app(ReportTaskProgress::class)->handle($this->task, $chair, TaskProgress::InProgress))->toThrow(WorkflowRuleBroken::class)
        ->and(fn () => app(ReportTaskProgress::class)->handle($this->task, $this->j->otherStaff, TaskProgress::InProgress))->toThrow(WorkflowRuleBroken::class);

    // The page: the General Secretary reports, the Chairperson reads, others are kept out.
    $page = route('follow-up.show', $this->assessment);
    $this->actingAs($this->ngs)->get($page)->assertOk()->assertSee('Approve a resource mobilisation plan')->assertSee('Where it stands');
    $this->actingAs($chair)->get($page)->assertOk()->assertSee('Approve a resource mobilisation plan')->assertDontSee('Where it stands');
    $this->actingAs($this->j->otherStaff)->get($page)->assertForbidden();

    // Before signing there is no follow-up.
    $open = Journey::open($this->j->assessor, $this->j->zambia, 'Mar 2028', now());
    $this->actingAs($this->j->assessor)->get(route('follow-up.show', $open))->assertForbidden();
});

it('reports and confirms through the page', function () {
    $this->actingAs($this->ngs)->post(route('follow-up.progress', $this->task), ['progress' => 'done', 'note' => 'Done in June.'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->j->assessor)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee('Waiting to confirm')->assertSee('Confirm done');
    $this->actingAs($this->j->assessor)->post(route('follow-up.confirm', $this->task), ['confirmed' => 1])->assertSessionHasNoErrors();

    expect($this->task->refresh()->done)->toBeTrue();
    $this->actingAs($this->ngs)->get(route('follow-up.show', $this->assessment))->assertSee('Confirmed done by '.$this->j->assessor->name);
});

it('keeps each task’s progress when a newer ODP version is read', function () {
    $task = OdpTask::query()->create([
        'assessment_id' => $this->assessment->id, 'source' => OdpTask::FROM_ODP, 'ref' => '2.1', 'priority' => 'GOVERNANCE', 'task' => 'Train the board',
    ]);
    app(ReportTaskProgress::class)->handle($task, $this->j->assessor, TaskProgress::Done);

    // A newer version of the same task, as read from the ODP (marked not done in the sheet).
    $replace = new ReflectionMethod(OdpTimeline::class, 'replace');
    $replace->invoke(null, $this->assessment, $this->j->odp($this->assessment)->versions()->latest('id')->firstOrFail(), [
        ['ref' => '2.1', 'priority' => 'GOVERNANCE', 'task' => 'Train the board', 'deadline_text' => null, 'due_on' => null, 'date_guessed' => false, 'responsible' => null, 'done' => false],
    ]);

    $after = OdpTask::query()->where('assessment_id', $this->assessment->id)->where('ref', '2.1')->firstOrFail();
    expect($after->progress)->toBe(TaskProgress::Done)->and($after->done)->toBeTrue();
});

it('plans three follow-up meetings from the signing, and records one with every task as it stood', function () {
    $signed = $this->j->odp($this->assessment)->signature->signed_at->copy()->startOfDay();
    $schedule = FollowUpSchedule::for($this->assessment);

    expect($schedule)->toHaveCount(3)
        ->and($schedule[0]['due_on']->toDateString())->toBe($signed->copy()->addMonthsNoOverflow(4)->toDateString())
        ->and($schedule[2]['due_on']->toDateString())->toBe($signed->copy()->addMonthsNoOverflow(12)->toDateString());

    app(ReportTaskProgress::class)->handle($this->task, $this->ngs, TaskProgress::InProgress, 'Drafting the plan.');
    $meeting = app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(),
        'Mwila Banda, Aminata Diallo', 'Reviewed the financial priorities. The plan goes to the board in March.',
        [$this->task->id => 'Draft shared with the finance committee.']);

    expect($meeting->tasks)->toHaveCount(1)
        ->and($meeting->tasks[0]->progress)->toBe(TaskProgress::InProgress)
        ->and($meeting->tasks[0]->update)->toBe('Draft shared with the finance committee.');
    Notification::assertSentTo([$this->ngs, $this->j->chair], WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'Follow-up meeting 1'));

    // The snapshot stays as it was when the task moves on.
    app(ReportTaskProgress::class)->handle($this->task, $this->j->assessor, TaskProgress::Done);
    expect($meeting->tasks()->first()->progress)->toBe(TaskProgress::InProgress);

    // Recording it again corrects it, rather than adding a second.
    app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(), null, 'Corrected summary.');
    expect($this->assessment->followUps()->count())->toBe(1)
        ->and($this->assessment->followUps()->first()->summary)->toBe('Corrected summary.');
});

it('lets only the assessors record a meeting, once held, and only for a planned one', function () {
    $record = fn (User $by, int $n = 1, ?string $on = null) => app(RecordFollowUp::class)
        ->handle($this->assessment, $by, $n, $on ?? today()->toDateString(), null, 'Summary.');

    expect(fn () => $record($this->ngs))->toThrow(WorkflowRuleBroken::class)
        ->and(fn () => $record($this->j->assessor, 4))->toThrow(WorkflowRuleBroken::class)
        ->and(fn () => $record($this->j->assessor, 1, today()->addDay()->toDateString()))->toThrow(WorkflowRuleBroken::class);
});

it('records a meeting through the page, and shows it to the movement', function () {
    $this->actingAs($this->j->assessor)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee('Meetings')->assertSee('Record this meeting');

    $this->actingAs($this->j->assessor)->post(route('follow-up.meetings.store', $this->assessment), [
        'number' => 1, 'held_on' => today()->toDateString(), 'attendees' => 'Mwila Banda', 'summary' => 'All priorities reviewed.',
        'updates' => [$this->task->id => 'On track.'],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->actingAs($this->ngs)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee('Held '.today()->asDay())->assertSee('All priorities reviewed.')->assertSee('On track.')
        ->assertDontSee('Record this meeting');
});
