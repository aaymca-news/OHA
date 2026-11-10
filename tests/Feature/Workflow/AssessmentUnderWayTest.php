<?php

use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadForm;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Queries\AllianceStats;
use App\Queries\MyWork;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * A movement whose assessment has started says so everywhere its health band is shown,
 * instead of "Not started", and offers no "Start new assessment" until that assessment
 * is finished (its ODP signed by the board): one assessment at a time.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('offers to start an assessment only while none is under way', function () {
    $page = fn () => $this->actingAs($this->j->assessor)->get(route('movements.show', $this->j->zambia))->assertOk();

    $page()->assertSee('Start new assessment')->assertDontSee('Assessment started');

    $assessment = $this->j->assessmentAt('form_uploaded');
    expect($this->j->zambia->assessmentUnderWay()?->id)->toBe($assessment->id);
    $page()->assertDontSee('Start new assessment')->assertSee('Assessment started')
        ->assertSee(route('assessments.show', $assessment), false);
});

it('offers the next assessment once the last one is finished, and shows its band', function () {
    $this->j->assessmentAt('odp_signed');

    expect($this->j->zambia->assessmentUnderWay())->toBeNull();
    $this->actingAs($this->j->assessor)->get(route('movements.show', $this->j->zambia))->assertOk()
        ->assertSee('Start new assessment')->assertDontSee('Assessment started');
});

it('refuses a second assessment while one is under way', function () {
    $this->j->assessmentAt('opened');

    $this->actingAs($this->j->assessor)->post(route('assessments.store', $this->j->zambia), ['period_label' => 'Mar 2026', 'period_start' => '2026-03'])
        ->assertSessionHasErrors(['action' => 'Zambia YMCA’s Feb 2026 assessment has already started. A new one can start once it is finished (its ODP signed by the board).']);
    expect($this->j->zambia->assessments()->count())->toBe(1);

    expect(fn () => app(OpenAssessment::class)->handle($this->j->admin, $this->j->zambia, 'Mar 2026', Carbon::parse('2026-03-01')))
        ->toThrow(WorkflowRuleBroken::class);
});

it('shows "Assessment started", not "Not started", on the lists and dashboards', function () {
    $this->j->assessmentAt('form_uploaded');

    $this->actingAs($this->j->admin)->get(route('movements.index'))->assertOk()->assertSee('Assessment started');
    $this->actingAs($this->j->admin)->get(route('movements.index', ['view' => 'table']))->assertOk()->assertSee('Assessment started');
    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()->assertSee('Assessment started');
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()->assertSee('Assessment started');
    $this->actingAs($this->j->chair)->get(route('movements.show', $this->j->zambia))->assertOk()->assertSee('Assessment started');

    // The band counts: Zambia counted as started, the others as not started.
    $rows = app(AllianceStats::class)->bandDistribution()->filter(fn (array $r) => $r['band']->code === 'notstarted')->values();
    expect($rows[0]['underWay'])->toBeTrue()->and($rows[0]['count'])->toBe(1)
        ->and($rows[1]['underWay'])->toBeFalse()->and($rows[1]['count'])->toBe(Movement::query()->count() - 1);
});

it('keeps My Work to four groups for an assessor: to be started, ongoing, done, missing information', function () {
    $this->j->otherStaff->assignedMovements()->attach([$this->j->zambia->id, $this->j->ghana->id]);
    $groups = fn () => collect(app(MyWork::class)->for($this->j->otherStaff))->keyBy('key');

    expect($groups()->keys()->all())->toBe(['start', 'started', 'done', 'gaps'])
        ->and($groups()['started']['items'])->toBe([]);

    $this->actingAs($this->j->otherStaff)->get(route('my-work'))->assertOk()
        ->assertSee('To be started')->assertSee('Ongoing assessments')->assertSee('Assessments done')->assertSee('Missing information')
        ->assertDontSee('Awaiting my action')->assertDontSee('with someone else');
});

it('lists the ongoing assessments, marking the ones waiting on the assessor', function () {
    $this->j->otherStaff->assignedMovements()->attach([$this->j->zambia->id, $this->j->ghana->id]);
    $groups = fn () => collect(app(MyWork::class)->for($this->j->otherStaff))->keyBy('key');
    $assessment = $this->j->assessmentAt('form_uploaded');

    // The form is with the assessors: theirs to act on, and on the badge.
    expect(collect($groups()['started']['items'])->pluck('movement.slug')->all())->toBe(['zambia'])
        ->and(collect($groups()['start']['items'])->pluck('movement.slug')->all())->toBe(['ghana'])
        ->and($groups()['started']['items'][0]['actionable'])->toBeTrue()
        ->and(app(MyWork::class)->actionableCount($this->j->otherStaff))->toBe(2);
    $this->actingAs($this->j->otherStaff)->get(route('my-work', ['group' => 'started']))->assertOk()
        ->assertSee('Ongoing assessments')->assertSee('Zambia YMCA · Feb 2026')->assertSee('Waiting on you')->assertDontSee('Start the assessment');

    // Sent to the Administrators: still ongoing, but with someone else.
    app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->assessor, acknowledgeGaps: true, reason: 'Following up.');
    expect($groups()['started']['items'][0]['actionable'])->toBeFalse()
        ->and(app(MyWork::class)->actionableCount($this->j->otherStaff))->toBe(1);
    $this->actingAs($this->j->otherStaff)->get(route('my-work', ['group' => 'started']))->assertOk()->assertSee('Assessment started');

    // An Administrator not assigned to Zambia does not see it among their ongoing assessments.
    expect(collect(app(MyWork::class)->for($this->j->secondAdmin))->keyBy('key')['started']['items'])->toBe([]);
});

it('leaves an assessment out of Missing information once its ODP is signed', function () {
    $this->j->assessmentAt('odp_approved');
    $gaps = fn () => collect(app(MyWork::class)->for($this->j->assessor))->keyBy('key')['gaps']['items'];
    expect($gaps())->toHaveCount(1); // the form went forward with gaps acknowledged

    $this->j->sign($this->j->odp($this->j->zambia->assessments()->firstOrFail()));
    expect($gaps())->toBe([]);
});

it('shows a signed assessment as Stage 1 done, its follow-ups ongoing, until the next one is due', function () {
    $this->j->assessmentAt('odp_signed');
    $groups = fn () => collect(app(MyWork::class)->for($this->j->assessor))->keyBy('key');

    // My Work: under "Assessments done", not "started" nor "to start".
    expect($groups()['started']['items'])->toBe([])
        ->and($groups()['start']['items'])->toBe([])
        ->and(collect($groups()['done']['items'])->pluck('movement.slug')->all())->toBe(['zambia'])
        ->and($groups()['done']['items'][0]['actionable'])->toBeFalse();
    $this->actingAs($this->j->assessor)->get(route('my-work', ['group' => 'done']))->assertOk()
        ->assertSee('Assessments done')->assertSee('Zambia YMCA · Feb 2026')->assertSee('Stage 1 done')->assertSee('Follow-ups ongoing')
        ->assertDontSee('Not started');

    // The movement page: the label, and starting the next one early tucked away.
    $this->actingAs($this->j->assessor)->get(route('movements.show', $this->j->zambia))->assertOk()
        ->assertSee('Stage 1 done')->assertSee('Follow-ups ongoing')->assertSee('Start the next assessment early');
    // The movement's own dashboard.
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()
        ->assertSee('Stage 1 done · follow-ups ongoing')->assertSee('Follow-up meetings')->assertDontSee('No assessment is under way');
    // The National Movements table.
    $this->actingAs($this->j->admin)->get(route('movements.index', ['view' => 'table']))->assertOk()->assertSee('Stage 1 done · follow-ups ongoing');

    // Once the next assessment is due, it is back among the movements to start.
    $this->travelTo(now()->addYears(3));
    expect($groups()['done']['items'])->toBe([])
        ->and(collect($groups()['start']['items'])->pluck('movement.slug')->all())->toBe(['zambia']);
    $this->actingAs($this->j->assessor)->get(route('my-work', ['group' => 'start']))->assertOk()->assertSee('Next assessment due');
});

it('keeps an assessment opened with nothing uploaded under "To be started", until the OHA form is uploaded', function () {
    $assessment = $this->j->assessmentAt('opened');
    $groups = fn () => collect(app(MyWork::class)->for($this->j->assessor))->keyBy('key');

    expect(collect($groups()['start']['items'])->pluck('movement.slug')->all())->toBe(['zambia'])
        ->and($groups()['start']['items'][0]['opened'] ?? false)->toBeTrue()
        ->and($groups()['started']['items'])->toBe([])
        ->and(app(MyWork::class)->actionableCount($this->j->assessor))->toBe(1);
    $this->actingAs($this->j->assessor)->get(route('my-work', ['group' => 'start']))->assertOk()
        ->assertSee('Zambia YMCA · Feb 2026')->assertSee('the OHA form is not uploaded yet')->assertSee('Upload the OHA form')
        ->assertSee(route('assessments.show', ['assessment' => $assessment->id, 'tab' => 'form']), false);

    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'ZAM26 OHA Form.xlsx', $this->j->assessor);

    expect($groups()['start']['items'])->toBe([])
        ->and(collect($groups()['started']['items'])->pluck('movement.slug')->all())->toBe(['zambia'])
        ->and(app(MyWork::class)->actionableCount($this->j->assessor))->toBe(1);
});
