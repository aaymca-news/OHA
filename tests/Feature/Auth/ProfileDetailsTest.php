<?php

use App\Models\AuditEvent;
use App\Models\Movement;
use App\Models\User;
use Database\Seeders\MovementsSeeder;

/*
 * My profile: each person corrects their own name and job title, and manages their
 * photo there. Their email (the sign-in), role and movements stay with the
 * Administrators. Password and sign-ins stay on the Security page.
 */

beforeEach(function () {
    $this->seed(MovementsSeeder::class);
    $this->user = User::factory()->create(['name' => 'Tendai Moyo', 'title' => null, 'email' => 'tendai@africaymca.org']);
    $this->user->assignedMovements()->attach(Movement::query()->where('slug', 'zambia')->firstOrFail());
});

it('shows the photo, the details to edit, and what the Administrators set, from the user menu', function () {
    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertSee(route('profile.show'), escape: false)->assertSee('My profile')
        ->assertSee(route('security.show'), escape: false)->assertSee('Security');

    $this->actingAs($this->user)->get(route('profile.show'))->assertOk()
        ->assertSee('Profile photo')->assertSee('Your details')
        ->assertSee('tendai@africaymca.org')->assertSee('AAYMCA Staff')->assertSee('Zambia YMCA')
        ->assertDontSee('Change password');

    // Security keeps the password and sign-ins, without the photo.
    $this->actingAs($this->user)->get(route('security.show'))->assertOk()
        ->assertSee('Change password')->assertDontSee('Profile photo');
});

it('lets them correct their own name and title, and records it', function () {
    $this->actingAs($this->user)->put(route('profile.update'), ['name' => 'Tendai  Moyo-Banda ', 'title' => 'Zonal Coordinator, Southern Africa'])
        ->assertSessionHas('status', 'Your details are saved.');

    expect($this->user->refresh()->name)->toBe('Tendai  Moyo-Banda')
        ->and($this->user->title)->toBe('Zonal Coordinator, Southern Africa')
        ->and(AuditEvent::query()->where('action', 'user.profile_updated')->value('payload')['changed'])->toEqualCanonicalizing(['name', 'title']);
});

it('never changes their email or role from here', function () {
    $this->actingAs($this->user)->put(route('profile.update'), ['name' => 'Tendai Moyo', 'email' => 'new@elsewhere.org', 'role' => 'super_admin']);

    expect($this->user->refresh()->email)->toBe('tendai@africaymca.org')
        ->and($this->user->role->value)->toBe('staff');
});

it('needs a name', function () {
    $this->actingAs($this->user)->put(route('profile.update'), ['name' => ' ', 'title' => 'x'])->assertSessionHasErrors('name');

    expect($this->user->refresh()->name)->toBe('Tendai Moyo');
});
