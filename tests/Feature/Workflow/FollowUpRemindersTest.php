<?php

use App\Actions\Movements\PauseFollowUp;
use App\Actions\Oha\ReportTaskProgress;
use App\Enums\TaskProgress;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\MovementStatus;
use App\Models\OdpTask;
use App\Models\User;
use App\Notifications\FollowUpDigestNotice;
use App\Notifications\WorkflowNotice;
use App\Queries\FollowUpDigest;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Stage 2 reminders: the weekly follow-up digest, the next assessment's reminders, and
 * an approved pause that stops them.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda']);
    $this->assessment = $this->j->assessmentAt('odp_signed');
    $task = fn (string $ref, string $text, ?string $due) => OdpTask::query()->create([
        'assessment_id' => $this->assessment->id, 'source' => OdpTask::TYPED, 'ref' => $ref, 'task' => $text, 'due_on' => $due,
    ]);
    $this->overdue = $task('1.1', 'Approve a resource mobilisation plan', today()->subDays(5)->toDateString());
    $this->soon = $task('2.1', 'Train the board', today()->addDays(10)->toDateString());
    $this->later = $task('3.1', 'Publish an annual report', today()->addMonths(6)->toDateString());
});

it('gathers what needs attention for each person: overdue, due soon, and for assessors what waits for them', function () {
    app(ReportTaskProgress::class)->handle($this->later, $this->ngs, TaskProgress::Done);

    $forNgs = app(FollowUpDigest::class)->for($this->ngs);
    $forAssessor = app(FollowUpDigest::class)->for($this->j->assessor);

    expect($forNgs)->toHaveCount(1)
        ->and($forNgs[0]['overdue']->pluck('ref')->all())->toBe(['1.1'])
        ->and($forNgs[0]['due_soon']->pluck('ref')->all())->toBe(['2.1'])
        ->and($forNgs[0]['waiting'])->toBeEmpty()
        ->and($forAssessor[0]['waiting']->pluck('ref')->all())->toBe(['3.1'])
        ->and(app(FollowUpDigest::class)->for($this->j->otherStaff))->toBe([]);
});

it('sends the digest once a week to those with something on it', function () {
    $this->artisan('oha:follow-up-digest')->assertSuccessful();

    Notification::assertSentTo($this->ngs, FollowUpDigestNotice::class);
    Notification::assertSentTo($this->j->assessor, FollowUpDigestNotice::class);
    Notification::assertNotSentTo($this->j->otherStaff, FollowUpDigestNotice::class);

    // The same week again: nothing more. The next week: again.
    $this->artisan('oha:follow-up-digest')->assertSuccessful();
    Notification::assertSentToTimes($this->ngs, FollowUpDigestNotice::class, 1);
    $this->travel(7)->days();
    $this->artisan('oha:follow-up-digest')->assertSuccessful();
    Notification::assertSentToTimes($this->ngs, FollowUpDigestNotice::class, 2);
});

it('writes the digest as one email with each movement’s tasks and a link', function () {
    $notice = FollowUpDigestNotice::from(app(FollowUpDigest::class)->for($this->ngs));
    $mail = $notice->toMail($this->ngs);
    $text = implode("\n", $mail->introLines);

    expect($mail->subject)->toBe('This week’s ODP follow-up')
        ->and($text)->toContain('Zambia YMCA')->toContain('Overdue:')->toContain('1.1 Approve a resource mobilisation plan')
        ->toContain('Due in the next 30 days:')->toContain(route('follow-up.show', $this->assessment))
        ->and($notice->toArray($this->ngs)['body'])->toContain('1 overdue');
});

it('reminds of the next assessment 90 and 30 days before, then once overdue, each once', function () {
    $due = MovementStatus::query()->findOrFail($this->j->zambia->id)->next_assessment_on;
    $reminded = fn () => Notification::sent($this->j->assessor, WorkflowNotice::class)
        ->filter(fn (WorkflowNotice $n) => str_contains($n->subject, 'next assessment'))->count();

    $this->travelTo($due->copy()->subDays(100));
    $this->artisan('oha:remind-reassessment');
    expect($reminded())->toBe(0);

    $this->travelTo($due->copy()->subDays(85));
    $this->artisan('oha:remind-reassessment');
    $this->artisan('oha:remind-reassessment');
    expect($reminded())->toBe(1);

    $this->travelTo($due->copy()->subDays(20));
    $this->artisan('oha:remind-reassessment');
    expect($reminded())->toBe(2);

    $this->travelTo($due->copy()->addDays(3));
    $this->artisan('oha:remind-reassessment');
    expect($reminded())->toBe(3);
    Notification::assertSentTo($this->j->admin, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'is overdue'));
});

it('sends only the most pressing reminder when it runs late', function () {
    $due = MovementStatus::query()->findOrFail($this->j->zambia->id)->next_assessment_on;
    $this->travelTo($due->copy()->addDays(2));
    $this->artisan('oha:remind-reassessment');

    $sent = Notification::sent($this->j->assessor, WorkflowNotice::class)->filter(fn (WorkflowNotice $n) => str_contains($n->subject, 'next assessment'));
    expect($sent)->toHaveCount(1)->and($sent->first()->subject)->toContain('is overdue');
});

it('pauses the follow-up: no digest or reminders, "Paused" shown, until it is lifted', function () {
    expect(fn () => app(PauseFollowUp::class)->pause($this->j->zambia, $this->j->assessor, today()->addMonth()->toDateString(), 'Elections'))
        ->toThrow(WorkflowRuleBroken::class);

    app(PauseFollowUp::class)->pause($this->j->zambia, $this->j->admin, today()->addMonth()->toDateString(), 'Board elections in December.');
    expect($this->j->zambia->refresh()->followUpPaused())->toBeTrue()
        ->and(app(FollowUpDigest::class)->for($this->ngs))->toBe([]);
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'is paused'));

    $this->actingAs($this->ngs)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee('Follow-up paused until')->assertSee('Board elections in December.')->assertDontSee('Lift the pause now');
    $this->actingAs($this->j->admin)->put(route('follow-up.pause', $this->assessment), ['resume' => 1])->assertSessionHasNoErrors();
    expect($this->j->zambia->refresh()->followUpPaused())->toBeFalse()
        ->and(app(FollowUpDigest::class)->for($this->ngs))->toHaveCount(1);
});

it('ends a pause after its last day', function () {
    app(PauseFollowUp::class)->pause($this->j->zambia, $this->j->admin, today()->addDays(2)->toDateString(), 'A crisis.');

    $this->travel(2)->days();
    expect($this->j->zambia->refresh()->followUpPaused())->toBeTrue();
    $this->travel(1)->days();
    expect($this->j->zambia->refresh()->followUpPaused())->toBeFalse();
});
