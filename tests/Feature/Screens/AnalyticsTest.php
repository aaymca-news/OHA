<?php

use App\Models\User;
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

it('shows the Stage 1 pipeline and a clear empty state before any form is approved', function () {
    $this->j->assessmentAt('form_submitted');

    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Stage 1 across the alliance')->assertSee('Not yet assessed')
        ->assertSee('No movement has an approved OHA form yet')->assertDontSee('Every movement, every category');
});

it('fills the dashboard from the approved forms', function () {
    $this->j->assessmentAt('form_approved');

    $response = $this->actingAs($this->j->otherStaff)->get(route('dashboard'))->assertOk();
    ValidHtml::assert($response->getContent(), 'dashboard with analytics');

    $response->assertSee('Every movement, every category')->assertSee('Zambia')
        ->assertSee('Where the money comes from')->assertSee('Paid services')
        ->assertSee('Reliance on international funding')->assertSee('23%')
        ->assertSee('Operating margin')->assertSee('+15.2%')
        ->assertSee('Policies in place')->assertSee('Vision 2030 in the Strategic Plan')
        ->assertSee('50,000');
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
