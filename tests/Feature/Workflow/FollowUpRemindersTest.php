<?php

use App\Actions\Movements\PauseFollowUp;
use App\Actions\Oha\FollowUpWindows;
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
    $this->assessment = $this->j->assessmentAt('closed');
    OdpTask::query()->create(['assessment_id' => $this->assessment->id, 'source' => OdpTask::TYPED, 'ref' => '1.1', 'task' => 'Approve a resource mobilisation plan']);
    // Follow-up 1 planned in ten days, and open.
    app(FollowUpWindows::class)->plan($this->assessment, $this->j->assessor, 1, today()->addDays(10)->toDateString());
    $this->j->openFollowUp($this->assessment);
});

it('gathers what needs attention for each person: the follow-up under way, and once all are closed the review', function () {
    $forNgs = app(FollowUpDigest::class)->for($this->ngs);

    expect($forNgs)->toHaveCount(1)
        ->and($forNgs[0]['follow_up'])->toMatchArray(['number' => 1, 'open' => true, 'overdue' => false])
        ->and($forNgs[0]['review_due'])->toBeFalse()
        ->and(app(FollowUpDigest::class)->for($this->j->otherStaff))->toBe([]);

    // Past its agreed date: overdue.
    $this->travel(11)->days();
    expect(app(FollowUpDigest::class)->for($this->ngs)[0]['follow_up']['overdue'])->toBeTrue();

    // The three closed: the review is for the assessors to write.
    foreach ([1, 2, 3] as $n) {
        $this->j->holdFollowUp($this->assessment, $n);
    }
    expect(app(FollowUpDigest::class)->for($this->j->assessor)[0])->toMatchArray(['follow_up' => null, 'review_due' => true])
        ->and(app(FollowUpDigest::class)->for($this->ngs))->toBe([]);
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

it('writes the digest as one email with each movement’s follow-up and a link', function () {
    $notice = FollowUpDigestNotice::from(app(FollowUpDigest::class)->for($this->ngs));
    $mail = $notice->toMail($this->ngs);
    $text = implode("\n", $mail->introLines);

    expect($mail->subject)->toBe('This week’s ODP follow-up')
        ->and($text)->toContain('Zambia YMCA')->toContain('Follow-up 1 is open, planned for')
        ->toContain('add the evidence and notes on the tasks')->toContain(route('follow-up.show', $this->assessment))
        ->and($notice->toArray($this->ngs)['body'])->toContain('Follow-up 1 is open');
});

it('has no band-based date for the next assessment: it is ready once the cycle is closed', function () {
    expect(MovementStatus::query()->findOrFail($this->j->zambia->id)->next_assessment_on)->toBeNull();
});

it('reminds of a planned first assessment 90 and 30 days before, then once past, each once', function () {
    $this->j->assessor->assignedMovements()->attach($this->j->ghana);
    $this->j->ghana->update(['planned_assessment_on' => today()->addMonths(6)->startOfMonth()->toDateString()]);
    $due = MovementStatus::query()->findOrFail($this->j->ghana->id)->next_assessment_on;
    $reminded = fn () => Notification::sent($this->j->assessor, WorkflowNotice::class)
        ->filter(fn (WorkflowNotice $n) => str_contains($n->subject, 'Ghana YMCA’s first assessment'))->count();

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
    Notification::assertSentTo($this->j->admin, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'is past its planned date'));
});

it('sends only the most pressing reminder when it runs late', function () {
    $this->j->assessor->assignedMovements()->attach($this->j->ghana);
    $this->j->ghana->update(['planned_assessment_on' => today()->subMonth()->startOfMonth()->toDateString()]);
    $this->artisan('oha:remind-reassessment');

    $sent = Notification::sent($this->j->assessor, WorkflowNotice::class)->filter(fn (WorkflowNotice $n) => str_contains($n->subject, 'first assessment'));
    expect($sent)->toHaveCount(1)->and($sent->first()->subject)->toContain('is past its planned date');
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
