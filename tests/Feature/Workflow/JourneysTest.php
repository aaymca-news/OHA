<?php

use App\Actions\Oha\Rescore;
use App\Models\Baseline;
use App\Models\User;
use App\Oha\Rescoring;
use App\Oha\ScoreGaps;
use App\Queries\Journeys;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * The journey of a cycle (decided 12 Nov 2026): each baseline's score, band and scores by
 * category, from Baseline 1 to the newest. On the movement page and the Scores and
 * baselines page for those who follow it up (never the Board Chairperson), and across the
 * Alliance for AAYMCA staff (Journeys, and the dashboard).
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create();
    $this->assessment = $this->j->assessmentAt('closed');

    // Follow-up 1: one answer now earns its point.
    $this->j->openFollowUp($this->assessment);
    $this->gained = collect(ScoreGaps::for(Rescoring::inForce($this->assessment, 1)->answers))
        ->flatten(1)->first(fn (array $i) => ($i['options'] ?? []) !== [] && $i['points'] === 0 && $i['max'] === 1);
    app(Rescore::class)->change($this->assessment, $this->j->assessor, 'q:'.$this->gained['code'], $this->gained['options'][0], 'Met at follow-up 1.');
    $this->j->holdFollowUp($this->assessment, 1);
});

it('follows the cycle baseline by baseline, as recorded', function () {
    $steps = app(Journeys::class)->of($this->assessment);
    $first = Baseline::query()->where('assessment_id', $this->assessment->id)->where('number', 1)->first()->total;

    expect(array_column($steps, 'number'))->toBe([1, 2])
        ->and($steps[0]['pct'])->toBe((float) $first->pct)
        ->and($steps[1]['points'])->toBe((float) $first->points_achieved + 1)
        ->and($steps[1]['changed'])->toBe(1)
        ->and(array_column(Journeys::curve($steps), 'pct'))->toBe([$steps[0]['pct'], $steps[1]['pct'], null, null]);

    $alliance = app(Journeys::class)->alliance();
    expect($alliance['summary'])->toMatchArray(['movements' => 1, 'followed' => 1, 'improved' => 1])
        ->and($alliance['rows'][0]['change'])->toBe(round($steps[1]['pct'] - $steps[0]['pct'], 1))
        ->and($alliance['curve'][1])->toMatchArray(['number' => 2, 'n' => 1]);
});

it('shows the journey to those who follow the cycle up, and the Alliance’s to AAYMCA staff', function () {
    $movement = route('movements.show', $this->j->zambia);
    $this->actingAs($this->j->assessor)->get($movement)->assertOk()->assertSee('cycle’s journey', false)->assertSee('Baseline 2');
    $this->actingAs($this->ngs)->get($movement)->assertOk()->assertSee('cycle’s journey', false);
    $this->actingAs($this->j->chair)->get($movement)->assertOk()->assertDontSee('cycle’s journey', false);

    $this->actingAs($this->j->otherStaff)->get(route('journeys.index'))->assertOk()->assertSee('Zambia YMCA')->assertSee('Moved up a band');
    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()->assertSee('Journeys this cycle');
    $this->actingAs($this->ngs)->get(route('journeys.index'))->assertForbidden();
    $this->actingAs($this->j->chair)->get(route('journeys.index'))->assertForbidden();
});
