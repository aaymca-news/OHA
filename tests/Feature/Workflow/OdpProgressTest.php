<?php

use App\Actions\Movements\PauseFollowUp;
use App\Actions\Oha\ConfirmTask;
use App\Actions\Oha\ReportTaskProgress;
use App\Enums\TaskProgress;
use App\Models\OdpTask;
use App\Models\User;
use App\Queries\OdpProgress;
use App\Support\OdpTimeline;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Stage 2 progress at a glance: an ODP's done (confirmed), overdue, waiting and blocked
 * tasks, by priority; and every movement side by side, falling behind first.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create();
    $this->assessment = $this->j->assessmentAt('odp_signed');
    $task = fn (string $ref, string $priority, ?string $due) => OdpTask::query()->create([
        'assessment_id' => $this->assessment->id, 'source' => OdpTask::TYPED, 'ref' => $ref, 'priority' => $priority, 'task' => 'Task '.$ref, 'due_on' => $due,
    ]);
    $this->a = $task('1.1', 'FINANCIAL STABILITY', today()->subDays(3)->toDateString());   // overdue
    $this->b = $task('1.2', 'FINANCIAL STABILITY', today()->addMonths(3)->toDateString());
    $this->c = $task('2.1', 'GOVERNANCE', today()->addMonths(3)->toDateString());
    $this->d = $task('2.2', 'GOVERNANCE', today()->addMonths(3)->toDateString());
});

it('counts an ODP’s progress, by priority, with only confirmed tasks as done', function () {
    app(ReportTaskProgress::class)->handle($this->b, $this->j->assessor, TaskProgress::Done);  // confirmed at once
    app(ReportTaskProgress::class)->handle($this->c, $this->ngs, TaskProgress::Done);          // waits
    app(ReportTaskProgress::class)->handle($this->d, $this->ngs, TaskProgress::Blocked, 'No funds yet.');

    $progress = OdpProgress::of($this->assessment);

    expect($progress['summary'])->toBe(['total' => 4, 'done' => 1, 'waiting' => 1, 'overdue' => 1, 'blocked' => 1, 'pct' => 25])
        ->and(collect($progress['priorities'])->pluck('priority')->all())->toBe(['FINANCIAL STABILITY', 'GOVERNANCE'])
        ->and($progress['priorities'][0]['done'])->toBe(1)->and($progress['priorities'][0]['overdue'])->toBe(1);
});

it('puts the movements falling behind first, and a paused one is not falling behind', function () {
    // (Only a signed ODP can fall behind: follow-up starts at signing.)
    $rows = OdpProgress::alliance(OdpTimeline::assessments());
    expect($rows[0]['assessment']->movement->slug)->toBe('zambia')
        ->and($rows[0]['signed'])->toBeTrue()
        ->and($rows[0]['behind'])->toBeTrue()
        ->and($rows[0]['meeting']['number'])->toBe(1);

    app(PauseFollowUp::class)->pause($this->j->zambia, $this->j->admin, today()->addMonth()->toDateString(), 'Elections.');
    expect(OdpProgress::alliance(OdpTimeline::assessments())[0]['behind'])->toBeFalse()
        ->and(OdpProgress::alliance(OdpTimeline::assessments())[0]['paused'])->toBeTrue();
});

it('shows the progress on the movement page, its dashboard and the Administrators’ dashboard', function () {
    $this->actingAs($this->j->assessor)->get(route('movements.show', $this->j->zambia))->assertOk()
        ->assertSee('ODP follow-up')->assertSee('0 of 4 tasks done')->assertSee('1 overdue')->assertSee('Open the follow-up');
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()
        ->assertSee('0 of 4 tasks done')->assertSee('Financial Stability')->assertSee('Governance');
    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Falling behind')->assertSee(route('follow-up.show', $this->assessment), false);

    // The movement's dashboard: a slim banner only for what waits on this person. The
    // General Secretary reports the overdue task; the Board Chairperson, who only follows
    // it, gets no banner (the Overdue figure and the ODP already show it).
    $this->actingAs($this->ngs)->get(route('dashboard'))->assertOk()
        ->assertSee('1 ODP task is overdue: report its progress')->assertSee('Your assessment cycle')->assertSee('Follow-up meetings')
        ->assertSee(route('follow-up.show', $this->assessment), false);
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()
        ->assertDontSee('ODP task is overdue')->assertDontSee('report its progress')->assertSee('Open the follow-up');

    // A task sent back by an assessor joins the banner.
    app(ReportTaskProgress::class)->handle($this->b, $this->ngs, TaskProgress::Done);
    app(ConfirmTask::class)->handle($this->b->refresh(), $this->j->assessor, false, 'The plan is not yet approved.');
    $this->actingAs($this->ngs)->get(route('dashboard'))->assertOk()->assertSee('1 ODP task is overdue and 1 sent back: report their progress');

    // Other staff, not following this movement up, see no follow-up card.
    $this->actingAs($this->j->otherStaff)->get(route('movements.show', $this->j->zambia))->assertOk()->assertDontSee('Open the follow-up');
});
