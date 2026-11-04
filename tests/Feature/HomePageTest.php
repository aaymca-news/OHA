<?php

use App\Models\Movement;
use App\Models\User;

it('shows a guest the public front page, which explains the OHA and links to sign in', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Strong, <span class="text-brand-red">healthy</span> YMCAs across Africa.', escape: false)
        ->assertSee(['Why it exists', 'The nine areas of a healthy YMCA.', 'How it works', 'Private by design. Shared by choice.'])
        ->assertSee(['Financial Stability', 'Staff &amp; Volunteer Development'], escape: false)
        ->assertSee(route('login'))
        ->assertSee('images/og-landing.png')
        ->assertDontSee('noindex');

    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('names the five health bands, weakest first, without their thresholds or timetable', function () {
    $this->get('/')
        ->assertSeeInOrder(['Critical', 'At Risk', 'Developing', 'Strong', 'Excellent'])
        ->assertDontSee(['Not started', 'From 20%', 'Assessed again', '6 months', '3 years']);
});

it('names no roles, approvals or signatures, so outsiders learn nothing of who does what', function () {
    $this->get('/')
        ->assertDontSee(['Board Chair', 'General Secretar', 'Administrator', 'assessor', 'Approve', 'Sign the', 'Signed by'], false);
});

it('reveals nothing about any movement on the front page', function () {
    $movement = Movement::factory()->create(['name' => 'Kilimanjaro YMCA']);
    User::factory()->create(['name' => 'Wanjiru Kamau']);

    $this->get('/')
        ->assertOk()
        ->assertDontSee($movement->name)
        ->assertDontSee('Wanjiru Kamau');
});

it('sends a signed-in user from the front page to their dashboard', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/dashboard');
});

it('keeps the sign-in page out of search engines and links it back to the front page', function () {
    $this->get(route('login'))
        ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false)
        ->assertSee('href="'.route('home').'"', escape: false);
});

it('shows the sign-in page with the AAYMCA branding', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Organizational Health &amp; Development', escape: false)
        ->assertSee('Africa Alliance of YMCAs')
        ->assertDontSee('Sign-ins, sign-outs and failed attempts are recorded');
});

it('shows a signed-in user their dashboard', function () {
    $user = User::factory()->create(['name' => 'Tendai Moyo']);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Tendai Moyo');
});
