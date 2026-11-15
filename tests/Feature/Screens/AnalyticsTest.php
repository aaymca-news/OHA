<?php

use App\Models\User;
use App\Models\Zone;
use App\Oha\ScoreGaps;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;
use Tests\Support\ValidHtml;

/*
 * The dashboard, the National Movements page and a movement's profile, drawn from
 * approved OHA forms: empty until something is approved, then filled from the answers.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('shows a clear empty state before any form is approved, and no work on the dashboard', function () {
    $this->j->assessmentAt('form_submitted');

    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Movements assessed')->assertSee('No movement is assessed yet')
        ->assertDontSee('Every movement, every area')->assertDontSee('Stage 1 across the region');
});

it('fills the dashboard from the approved forms', function () {
    $this->j->assessmentAt('form_approved');

    $response = $this->actingAs($this->j->otherStaff)->get(route('dashboard'))->assertOk();
    ValidHtml::assert($response->getContent(), 'dashboard with analytics');

    $response->assertSee('Who needs support')->assertSee('Every movement, every area')->assertSee('Zambia')
        ->assertSee('Most often missed')->assertSee('Areas, weakest first')
        ->assertSee('Where the money comes from')->assertSee('Paid services')
        ->assertSee('Financial resilience')->assertSee('23%')
        ->assertSee('Operating margin')->assertSee('+15.2%')
        ->assertSee('Policies in place')->assertSee('Vision 2030 in the Strategic Plan')
        ->assertSee('50,000');
});

it('filters the dashboard by zone, and keeps every zone side by side', function () {
    $this->j->assessmentAt('form_approved');
    $home = $this->j->zambia->zone;
    $other = Zone::query()->whereKeyNot($home->id)->orderBy('name')->firstOrFail();

    $this->actingAs($this->j->admin)->get(route('dashboard', ['zone' => $home->id]))->assertOk()
        ->assertSee('Who needs support')->assertSee('Zambia')->assertSee('By zone')->assertSee($other->name);
    $this->actingAs($this->j->admin)->get(route('dashboard', ['zone' => $other->id]))->assertOk()
        ->assertSee('Movements assessed')->assertSee('No movement in '.$other->name.' is assessed yet')->assertDontSee('Who needs support');
});

it('names every scored question shortly, for the points most often missed', function () {
    foreach (ScoreGaps::scored() as $items) {
        foreach ($items as $item) {
            expect(ScoreGaps::name($item['code']))->not->toBe($item['code']);
        }
    }
});

it('shows every movement as a card, grouped by zone, with its stage and assessors', function () {
    $this->j->assessmentAt('report_uploaded');

    $response = $this->actingAs($this->j->admin)->get(route('movements.index'))->assertOk();
    ValidHtml::assert($response->getContent(), 'movements cards');

    $response->assertSee('Southern Africa')->assertSee('East Africa')
        ->assertSee('Report under way')->assertSee('Tendai Moyo')
        ->assertSee('No assessor')->assertSee('Not yet assessed');

    // Cards link to their movement (the roles banner names movements too, without links).
    $card = fn (string $slug) => 'href="'.route('movements.show', $slug).'"';
    $this->actingAs($this->j->admin)->get(route('movements.index', ['stage' => 'report']))->assertSee($card('zambia'), false)->assertDontSee($card('kenya'), false);
    $this->actingAs($this->j->admin)->get(route('movements.index', ['q' => 'lusaka']))->assertSee($card('zambia'), false)->assertDontSee($card('ghana'), false);
    $this->actingAs($this->j->admin)->get(route('movements.index', ['view' => 'table']))->assertOk()->assertSee('Next assessment');
});

it('shows a movement at a glance from its approved form, to staff and to its Chairperson', function () {
    $this->j->assessmentAt('report_approved');

    foreach ([$this->j->otherStaff, $this->j->chair] as $user) {
        $response = $this->actingAs($user)->get(route('movements.show', $this->j->zambia))->assertOk();
        ValidHtml::assert($response->getContent(), 'Zambia profile');
        $response->assertSee('At a glance')->assertSee('Zambia Kwacha')->assertSee('7.6M')
            ->assertSee('Policies in place')->assertSee('Safeguarding')->assertSee('What the movement wants to improve');
    }

    $this->actingAs($this->j->otherStaff)->get(route('movements.show', $this->j->ghana))->assertOk()->assertDontSee('At a glance');
});

it('keeps another movement’s Chairperson away from the profile', function () {
    $this->j->assessmentAt('form_approved');

    $this->actingAs(User::factory()->chair($this->j->ghana->refresh())->make(['id' => $this->j->ghanaChair->id]))
        ->get(route('movements.show', $this->j->zambia))->assertForbidden();
});
