<?php

use App\Models\AuditEvent;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'tendai.moyo@aaymca.test']);
});

it('signs a user in and sends them to the dashboard', function () {
    $this->post(route('login.store'), ['email' => 'tendai.moyo@aaymca.test', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($this->user);
    expect(AuditEvent::query()->where('action', 'auth.signed_in')->where('subject_id', $this->user->id)->exists())->toBeTrue();
});

it('accepts the email in any letter case', function () {
    $this->post(route('login.store'), ['email' => 'Tendai.Moyo@AAYMCA.test', 'password' => 'password']);

    $this->assertAuthenticatedAs($this->user);
});

it('refuses a wrong password and records the attempt', function () {
    $this->post(route('login.store'), ['email' => 'tendai.moyo@aaymca.test', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(AuditEvent::query()->where('action', 'auth.sign_in_failed')->where('subject_id', $this->user->id)->exists())->toBeTrue();
});

it('refuses a deactivated account, even with the right password', function () {
    $this->user->update(['active' => false]);

    $this->post(route('login.store'), ['email' => 'tendai.moyo@aaymca.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('locks sign-in after five failed attempts', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => 'tendai.moyo@aaymca.test', 'password' => 'wrong']);
    }

    $this->post(route('login.store'), ['email' => 'tendai.moyo@aaymca.test', 'password' => 'password'])
        ->assertStatus(429);

    $this->assertGuest();
});

it('records signing out', function () {
    $this->actingAs($this->user)->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
    expect(AuditEvent::query()->where('action', 'auth.signed_out')->exists())->toBeTrue();
});

it('has no public sign-up', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'X', 'email' => 'x@example.org', 'password' => 'Password12345'])->assertNotFound();
});

it('has no two-step verification: every role signs in with a password alone', function () {
    $admin = User::factory()->superAdmin()->create(['email' => 'achieng@aaymca.test']);

    $this->post(route('login.store'), ['email' => 'achieng@aaymca.test', 'password' => 'password'])
        ->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($admin);

    $this->post('/user/two-factor-authentication')->assertNotFound();
    $this->get('/two-factor-challenge')->assertNotFound();
    $this->actingAs($admin)->get(route('security.show'))->assertOk()->assertDontSee('Two-step verification');
});
