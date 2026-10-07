<?php

use App\Models\User;
use Database\Seeders\MovementsSeeder;

/*
 * Error pages are the platform's own: they say what happened in plain words, offer a way
 * back, and read on a phone. A refusal shows the reason the platform gave.
 */

it('answers a missing page with its own page and a way back', function () {
    $this->get('/no-such-page')->assertNotFound()
        ->assertSee('This page does not exist')->assertSee('Go to the dashboard')
        ->assertSee('name="viewport"', false);
});

it('shows why something is refused', function () {
    $this->seed(MovementsSeeder::class);
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('admin.users.index'))->assertForbidden()
        ->assertSee('This is not open to you')->assertSee('Error 403');
});
