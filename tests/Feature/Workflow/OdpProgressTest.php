<?php

use App\Actions\Movements\PauseFollowUp;
use App\Actions\Oha\FollowUpWindows;
use App\Actions\Oha\RecordFollowUp;
use App\Enums\TaskProgress;
use App\Models\OdpTask;
use App\Models\User;
use App\Queries\OdpProgress;
use App\Support\OdpTimeline;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Stage 2 progress at a glance (decided 12 Nov 2026): an ODP's tasks done, in progress and
 * blocked, as set at the last follow-up meeting, by priority; and every movement side by
 * side, falling behind (a follow-up past its agreed date) first. Tasks have no deadlines.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create();
    $this->assessment = $this->j->assessmentAt('closed');
    $task = fn (string $ref, string $priority) => OdpTask::query()->create([
        'assessment_id' => $this->assessment->id, 'source' => OdpTask::TYPED, 'ref' => $ref, 'priority' => $priority, 'task' => 'Task '.$ref,
    ]);
    $this->a = $task('1.1', 'FINANCIAL STABILITY');
    $this->b = $task('1.2', 'FINANCIAL STABILITY');
    $this->c = $task('2.1', 'GOVERNANCE');
    $this->d = $task('2.2', 'GOVERNANCE');
    // Set at follow-up 1's meeting, for every task together.
    $this->meeting = function (array $statuses): void {
        $this->j->openFollowUp($this->assessment);
        app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(), null, 'Tasks reviewed.', statuses: $statuses);
    };
});

it('counts an ODP’s progress, by priority, as set at the last follow-up meeting', function () {
    ($this->meeting)([
        $this->a->id => TaskProgress::InProgress->value, $this->b->id => TaskProgress::Done->value,
        $this->c->id => TaskProgress::NotStarted->value, $this->d->id => TaskProgress::Blocked->value,
    ]);

    $progress = OdpProgress::of($this->assessment);

    expect($progress['summary'])->toBe(['total' => 4, 'done' => 1, 'in_progress' => 1, 'blocked' => 1, 'pct' => 25])
        ->and(collect($progress['priorities'])->pluck('priority')->all())->toBe(['FINANCIAL STABILITY', 'GOVERNANCE'])
        ->and($progress['priorities'][0]['done'])->toBe(1)->and($progress['priorities'][1]['blocked'])->toBe(1)
        ->and($this->b->refresh()->done)->toBeTrue()
        ->and($this->b->confirmer->is($this->j->assessor))->toBeTrue();
});

it('puts the movements falling behind first, and a paused one is not falling behind', function () {
    // Falling behind: a follow-up past the date agreed for it, and not closed.
    app(FollowUpWindows::class)->plan($this->assessment, $this->j->assessor, 1, today()->subDay()->toDateString());
    $rows = OdpProgress::alliance(OdpTimeline::assessments());
    expect($rows[0]['assessment']->movement->slug)->toBe('zambia')
        ->and($rows[0]['closed'])->toBeTrue()
        ->and($rows[0]['behind'])->toBeTrue()
        ->and($rows[0]['meeting']['number'])->toBe(1);

    app(PauseFollowUp::class)->pause($this->j->zambia, $this->j->admin, today()->addMonth()->toDateString(), 'Elections.');
    expect(OdpProgress::alliance(OdpTimeline::assessments())[0]['behind'])->toBeFalse()
        ->and(OdpProgress::alliance(OdpTimeline::assessments())[0]['paused'])->toBeTrue();
});

it('shows the progress on the movement page, its dashboard and the Administrators’ dashboard', function () {
    ($this->meeting)([$this->d->id => TaskProgress::Blocked->value]);
    app(FollowUpWindows::class)->plan($this->assessment, $this->j->assessor, 1, today()->subDay()->toDateString());

    $this->actingAs($this->j->assessor)->get(route('movements.show', $this->j->zambia))->assertOk()
        ->assertSee('ODP follow-up')->assertSee('0 of 4 tasks done')->assertSee('1 blocked')->assertSee('Open the follow-up');
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()
        ->assertSee('0 of 4 tasks done')->assertSee('Financial Stability')->assertSee('Governance');
    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Follow-ups overdue')->assertSee('Falling behind')->assertSee(route('follow-up.show', $this->assessment), false);

    // The movement's dashboard: a slim banner only for what waits on this person. The
    // General Secretary is told the follow-up is open (for evidence and notes); the Board
    // Chairperson sees the ODP's progress but not the follow-up itself (decided 12 Nov 2026).
    $this->actingAs($this->ngs)->get(route('dashboard'))->assertOk()
        ->assertSee('Follow-up 1 is open')->assertSee('add your evidence and notes on the tasks')->assertSee('Your assessment cycle')->assertSee('Follow-ups')
        ->assertSee(route('follow-up.show', $this->assessment), false);
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()
        ->assertDontSee('Follow-up 1 is open')->assertDontSee('Open the follow-up')
        ->assertDontSee(route('follow-up.show', $this->assessment), false);

    // Other staff, not following this movement up, see no follow-up card.
    $this->actingAs($this->j->otherStaff)->get(route('movements.show', $this->j->zambia))->assertOk()->assertDontSee('Open the follow-up');
});
