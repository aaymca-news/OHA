<?php

use App\Actions\Oha\OpenAssessment;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Queries\AllianceStats;
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
