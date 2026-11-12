<?php

use App\Actions\Evidence\RemoveEvidence;
use App\Actions\Evidence\UploadEvidence;
use App\Actions\Oha\FollowUpWindows;
use App\Actions\Oha\RecordFollowUp;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\AuditEvent;
use App\Models\Evidence;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\FollowUpSchedule;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * The follow-ups (decided 12 Nov 2026): three, with no fixed dates. The movement's
 * assessors, an Administrator or the Super Administrator set each one's date (as agreed
 * with the movement), open it (never by itself), record its meeting and close it, which
 * locks what it counted (its meeting, where each task stood, the evidence added for it).
 * In order. Past its date and not closed, it is overdue; it never closes by itself. Only
 * an Administrator reopens the last one closed, with a reason.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda']);
    $this->travelTo(now()->setDate(2026, 3, 10)->setTime(9, 0));
    $this->assessment = $this->j->assessmentAt('closed');
    $this->windows = app(FollowUpWindows::class);
    $this->state = fn (int $n) => collect(FollowUpSchedule::for($this->assessment->refresh()))->firstWhere('number', $n);
    $this->evidence = fn (string $title, ?User $by = null) => app(UploadEvidence::class)->handle($by ?? $this->ngs, $this->assessment->refresh(), null, null, $title, url: 'https://example.org/'.str($title)->slug());
});

it('has no fixed dates: each is set as agreed, changed until closed, and overdue once passed, never closing by itself', function () {
    expect(collect(FollowUpSchedule::for($this->assessment))->pluck('due_on')->filter()->all())->toBe([]);

    foreach ([$this->ngs, $this->j->chair, $this->j->otherStaff] as $who) {
        expect(fn () => $this->windows->plan($this->assessment, $who, 1, '2026-06-30'))->toThrow(WorkflowRuleBroken::class, 'run by the movement’s assessors or an Administrator');
    }
    $this->windows->plan($this->assessment, $this->j->assessor, 1, '2026-06-30');
    $this->windows->plan($this->assessment, $this->j->superAdmin, 3, '2027-06-30');
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => $n->subject === 'Follow-up 1 planned: Zambia YMCA');

    // Changed later, as agreed: no one-year limit.
    $this->windows->plan($this->assessment, $this->j->admin, 1, '2026-09-15');
    expect(($this->state)(1)['due_on']->toDateString())->toBe('2026-09-15')
        ->and(($this->state)(3)['due_on']->toDateString())->toBe('2027-06-30')
        ->and(AuditEvent::query()->where('action', 'follow_up.date_set')->count())->toBe(3)
        ->and(fn () => $this->windows->plan($this->assessment, $this->j->admin, 1, '2026-02-30'))->toThrow(WorkflowRuleBroken::class, 'real date');

    // Past its date, not closed: overdue, and still upcoming (nothing opens or closes by itself).
    $this->travelTo('2026-09-16 08:00');
    expect(($this->state)(1)['overdue'])->toBeTrue()
        ->and(($this->state)(1)['state'])->toBe(FollowUpSchedule::UPCOMING);
});

it('is opened only by its assessors or an Administrator, in order, and the movement is told', function () {
    $this->travelTo('2027-01-01 08:00');
    expect(($this->state)(1)['state'])->toBe(FollowUpSchedule::UPCOMING)
        ->and(fn () => app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(), null, 'Too soon.'))
        ->toThrow(WorkflowRuleBroken::class, 'Open follow-up 1 first')
        ->and(fn () => $this->windows->open($this->assessment, $this->ngs))->toThrow(WorkflowRuleBroken::class);

    Notification::fake();
    $this->windows->open($this->assessment, $this->j->assessor);

    expect(($this->state)(1)['state'])->toBe(FollowUpSchedule::OPEN)
        ->and(($this->state)(2)['state'])->toBe(FollowUpSchedule::UPCOMING)
        ->and(fn () => $this->windows->open($this->assessment, $this->j->admin))->toThrow(WorkflowRuleBroken::class, 'already open');
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => $n->subject === 'Follow-up 1 is open: Zambia YMCA');
    Notification::assertNothingSentTo($this->j->chair);
});

it('closes a follow-up once its meeting is recorded, which locks it, and can date the next', function () {
    $this->j->openFollowUp($this->assessment);
    expect(fn () => $this->windows->close($this->assessment, $this->j->assessor, 1))->toThrow(WorkflowRuleBroken::class, 'Record the meeting');

    app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(), null, 'Tasks reviewed.');
    foreach ([$this->ngs, $this->j->chair, $this->j->otherStaff] as $who) {
        expect(fn () => $this->windows->close($this->assessment, $who, 1))->toThrow(WorkflowRuleBroken::class, 'run by the movement’s assessors or an Administrator');
    }
    Notification::fake();
    $this->windows->close($this->assessment, $this->j->assessor, 1, '2026-08-20');

    expect(($this->state)(1)['state'])->toBe(FollowUpSchedule::LOCKED)
        ->and(($this->state)(2)['due_on']->toDateString())->toBe('2026-08-20')
        ->and(($this->state)(2)['state'])->toBe(FollowUpSchedule::UPCOMING)
        ->and(AuditEvent::query()->where('action', 'follow_up.locked')->value('payload'))->toMatchArray(['number' => 1])
        // Locked: its meeting can no longer be corrected, nor its date changed.
        ->and(fn () => app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(), null, 'Again.'))
        ->toThrow(WorkflowRuleBroken::class, 'closed and locked')
        ->and(fn () => $this->windows->plan($this->assessment, $this->j->admin, 1, '2026-04-01'))->toThrow(WorkflowRuleBroken::class, 'is closed');
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => $n->subject === 'Follow-up 1 closed: Zambia YMCA'
        && str_contains($n->body, 'Follow-up 2 is planned for 20 Aug 2026'));
    Notification::assertNothingSentTo($this->j->chair);

    // An Administrator opens and closes the next one too; its meeting is recorded by the assessor.
    $this->j->openFollowUp($this->assessment, $this->j->admin);
    app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 2, today()->toDateString(), null, 'Tasks reviewed again.');
    $this->windows->close($this->assessment, $this->j->admin, 2);
    expect(($this->state)(2)['window']->locker->is($this->j->admin))->toBeTrue();
});

it('lets only an Administrator reopen the last follow-up closed, saying why', function () {
    $this->j->holdFollowUp($this->assessment, 1);
    $this->j->holdFollowUp($this->assessment, 2);

    expect(fn () => $this->windows->reopen($this->assessment, $this->j->assessor, 2, 'A task was wrong.'))->toThrow(WorkflowRuleBroken::class, 'Only an Administrator')
        ->and(fn () => $this->windows->reopen($this->assessment, $this->j->admin, 2, ' '))->toThrow(WorkflowRuleBroken::class, 'Say why')
        ->and(fn () => $this->windows->reopen($this->assessment, $this->j->admin, 1, 'Too late.'))->toThrow(WorkflowRuleBroken::class, 'Only the last follow-up closed (follow-up 2)');

    $this->windows->reopen($this->assessment, $this->j->admin, 2, 'A task was recorded under the wrong priority.');
    $second = ($this->state)(2);

    expect($second['state'])->toBe(FollowUpSchedule::OPEN)
        ->and($second['window']->reopen_reason)->toBe('A task was recorded under the wrong priority.')
        ->and(($this->state)(3)['state'])->toBe(FollowUpSchedule::UPCOMING);
    app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 2, today()->toDateString(), null, 'Corrected.');
    $this->windows->close($this->assessment, $this->j->assessor, 2);
    expect(($this->state)(2)['state'])->toBe(FollowUpSchedule::LOCKED);
});

it('counts each piece of evidence in the assessment or the follow-up under way, and locks it with it', function () {
    $this->j->assessor->assignedMovements()->attach($this->j->ghana);
    $later = Journey::open($this->j->assessor, $this->j->ghana, 'Mar 2026', now());
    expect(FollowUpSchedule::countedIn($later))->toBeNull();

    $first = ($this->evidence)('Board minutes, March');
    expect($first->counted_in)->toBe(1)
        ->and($first->countedPill())->toBe(['info', 'For Follow-up 1']);

    // While follow-up 1 is not closed, the General Secretary can still take it back.
    expect($this->ngs->can('remove', $first))->toBeTrue();
    $this->j->holdFollowUp($this->assessment, 1);
    expect($first->refresh()->countedPill())->toBe(['good', 'Counted in Follow-up 1'])
        ->and($this->ngs->can('remove', $first))->toBeFalse()
        ->and(fn () => app(RemoveEvidence::class)->handle($this->ngs, $first))->toThrow(WorkflowRuleBroken::class, 'counted in Follow-up 1, which is closed')
        // AAYMCA staff still can.
        ->and($this->j->assessor->can('remove', $first))->toBeTrue();

    expect(($this->evidence)('Audit report 2025')->counted_in)->toBe(2);
    $this->j->holdFollowUp($this->assessment, 2);
    $this->j->holdFollowUp($this->assessment, 3);
    $after = ($this->evidence)('New strategic plan');
    expect($after->counted_in)->toBe(FollowUpSchedule::NEXT_ASSESSMENT)
        ->and($after->countedPill())->toBe(['info', 'For the next assessment'])
        ->and(Evidence::query()->whereNull('counted_in')->count())->toBe(0);

    $this->actingAs($this->ngs)->get(route('evidence.index', $this->j->zambia))->assertOk()
        ->assertSee('Counted in Follow-up 1')->assertSee('Counted in Follow-up 2')->assertSee('For the next assessment');
});

it('runs the follow-ups from the follow-up page', function () {
    $page = route('follow-up.show', $this->assessment);

    $this->actingAs($this->ngs)->get($page)->assertOk()
        ->assertSee('date not set yet')->assertSee('Not open yet')->assertDontSee('Set the date')->assertDontSee('Open follow-up 1');
    $this->actingAs($this->j->admin)->get($page)->assertOk()->assertSee('Set the date')->assertSee('Open follow-up 1');

    $this->actingAs($this->j->assessor)->put(route('follow-up.plan', ['assessment' => $this->assessment, 'number' => 1]), ['due_on' => '2026-06-30'])->assertSessionHasNoErrors();
    $this->actingAs($this->j->assessor)->post(route('follow-up.open', $this->assessment))->assertSessionHasNoErrors();
    $this->actingAs($this->j->assessor)->get($page)->assertOk()->assertSee('planned 30 Jun 2026')->assertSee('Record the meeting');

    $this->actingAs($this->j->assessor)->post(route('follow-up.meetings.store', $this->assessment), [
        'number' => 1, 'held_on' => today()->toDateString(), 'summary' => 'All priorities reviewed.',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->j->assessor)->get($page)->assertOk()->assertSee('Close follow-up 1')->assertSee('Follow-up 2’s date (optional)');
    $this->actingAs($this->j->assessor)->post(route('follow-up.lock', ['assessment' => $this->assessment, 'number' => 1]), ['next_due_on' => '2026-10-01'])->assertSessionHasNoErrors();

    $this->actingAs($this->j->assessor)->get($page)->assertOk()->assertSee('Closed 10 Mar 2026')->assertSee('planned 1 Oct 2026')->assertDontSee('Reopen follow-up 1');
    $this->actingAs($this->j->admin)->get($page)->assertOk()->assertSee('Reopen follow-up 1');
    $this->actingAs($this->j->admin)->post(route('follow-up.reopen', ['assessment' => $this->assessment, 'number' => 1]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->j->admin)->post(route('follow-up.reopen', ['assessment' => $this->assessment, 'number' => 1]), ['reason' => 'Wrong date.'])->assertSessionHasNoErrors();
    $this->actingAs($this->j->assessor)->get($page)->assertOk()->assertSee('Reopened 10 Mar 2026')->assertSee('Wrong date.');
});

it('asks for the first follow-up’s date when the assessment is closed from its page', function () {
    $assessment = $this->j->assessmentAt('odp_approved');
    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']))->assertOk()
        ->assertSee('Follow-up 1’s date (optional)');
    $this->actingAs($this->j->assessor)->post(route('assessments.close', $assessment), ['first_follow_up_on' => '2026-07-01'])->assertSessionHasNoErrors();

    expect(collect(FollowUpSchedule::for($assessment->refresh()))->firstWhere('number', 1)['due_on']->toDateString())->toBe('2026-07-01');
});
