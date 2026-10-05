<?php

use App\Models\User;

it('sends a guest from the home page to sign in', function () {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect(route('login'));
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
