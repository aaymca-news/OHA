<?php

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Movement;
use App\Models\User;
use App\Notifications\InvitationNotice;
use Database\Seeders\MovementsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->superAdmin()->create(['name' => 'Achieng Odhiambo']);
});

/** Invite someone through the screen and return the token from their email. */
function inviteThroughScreen(User $admin, array $overrides = []): string
{
    test()->actingAs($admin)->post(route('admin.users.store'), $overrides + [
        'name' => 'Aminata Diallo',
        'email' => 'Aminata.Diallo@aaymca.test',
        'role' => 'staff',
        'title' => 'Zonal Coordinator, West Africa',
    ])->assertRedirect();

    $invitee = User::query()->where('email', 'aminata.diallo@aaymca.test')->firstOrFail();
    $token = null;
    Notification::assertSentTo($invitee, InvitationNotice::class, function (InvitationNotice $n) use (&$token) {
        $token = $n->token;

        return true;
    });
    auth()->logout();

    return (string) $token;
}

it('creates an account that cannot be used until the invitation is accepted', function () {
    inviteThroughScreen($this->admin);
    $invitee = User::query()->where('email', 'aminata.diallo@aaymca.test')->firstOrFail();

    expect($invitee->isInvitationPending())->toBeTrue()
        ->and($invitee->role)->toBe(Role::Staff)
        ->and(AuditEvent::query()->where('action', 'user.invited')->where('actor_id', $this->admin->id)->exists())->toBeTrue();

    $this->post(route('login.store'), ['email' => 'aminata.diallo@aaymca.test', 'password' => 'password'])->assertSessionHasErrors('email');
});

it('lets the invited person set a password, confirms their email and signs them in', function () {
    $token = inviteThroughScreen($this->admin);

    $this->get(route('invitation.show', ['token' => $token, 'email' => 'aminata.diallo@aaymca.test']))->assertOk()->assertSee('Set your password');

    $this->post(route('invitation.store'), [
        'token' => $token, 'email' => 'aminata.diallo@aaymca.test',
        'password' => 'Kumasi-Road-2026', 'password_confirmation' => 'Kumasi-Road-2026',
    ])->assertRedirect(route('dashboard'));

    $invitee = User::query()->where('email', 'aminata.diallo@aaymca.test')->firstOrFail();
    $this->assertAuthenticatedAs($invitee);
    expect($invitee->isInvitationPending())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'user.invitation_accepted')->exists())->toBeTrue();
});

it('uses each invitation link only once', function () {
    $token = inviteThroughScreen($this->admin);
    $accept = fn () => $this->post(route('invitation.store'), [
        'token' => $token, 'email' => 'aminata.diallo@aaymca.test',
        'password' => 'Kumasi-Road-2026', 'password_confirmation' => 'Kumasi-Road-2026',
    ]);

    $accept();
    auth()->logout();

    $accept()->assertSessionHasErrors('email');
});

it('lets an invitation expire after seven days', function () {
    $token = inviteThroughScreen($this->admin);
    $this->travel(8)->days();

    $this->post(route('invitation.store'), [
        'token' => $token, 'email' => 'aminata.diallo@aaymca.test',
        'password' => 'Kumasi-Road-2026', 'password_confirmation' => 'Kumasi-Road-2026',
    ])->assertSessionHasErrors(['email' => 'This invitation link is invalid or has expired. Ask an Administrator to send a new one.']);
});

it('insists on a strong password', function () {
    $token = inviteThroughScreen($this->admin);

    $this->post(route('invitation.store'), [
        'token' => $token, 'email' => 'aminata.diallo@aaymca.test', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');
});

it('invites a Board Chairperson straight onto their movement', function () {
    $this->seed(MovementsSeeder::class);
    $zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();

    inviteThroughScreen($this->admin, ['role' => 'board', 'board_movement_id' => $zambia->id]);
    $invitee = User::query()->where('email', 'aminata.diallo@aaymca.test')->firstOrFail();

    expect($invitee->role)->toBe(Role::Board)
        ->and($invitee->movement_id)->toBe($zambia->id)
        ->and($zambia->chair()->first()->id)->toBe($invitee->id);
});

it('asks before an invitation replaces a movement’s Board Chairperson', function () {
    $this->seed(MovementsSeeder::class);
    $zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
    $chair = User::factory()->chair($zambia)->create(['name' => 'Naledi Moyo']);

    $this->actingAs($this->admin)->from(route('admin.users.create'))->post(route('admin.users.store'), [
        'name' => 'Aminata Diallo', 'email' => 'aminata@aaymca.test', 'role' => 'board', 'board_movement_id' => $zambia->id,
    ])->assertRedirect(route('admin.users.create'))->assertSessionHasErrors(['action' => 'Naledi Moyo is Zambia YMCA’s Board Chairperson, and a movement has one. Confirm to replace them: their account will be deactivated.']);

    // Nothing was created: the whole invitation rolled back.
    expect(User::query()->where('email', 'aminata@aaymca.test')->exists())->toBeFalse()
        ->and($chair->refresh()->active)->toBeTrue();
});

it('lets only the Super Administrator invite an Administrator', function () {
    $admin = User::factory()->admin()->create(['name' => 'Gloria Anyika']);

    $this->actingAs($admin)->from(route('admin.users.create'))->post(route('admin.users.store'), [
        'name' => 'Aminata Diallo', 'email' => 'aminata@aaymca.test', 'role' => 'admin',
    ])->assertSessionHasErrors(['action' => 'Only the Super Administrator gives the Administrator roles.']);
    expect(User::query()->where('email', 'aminata@aaymca.test')->exists())->toBeFalse();

    inviteThroughScreen($this->admin, ['role' => 'admin']);
    expect(User::query()->where('email', 'aminata.diallo@aaymca.test')->value('role'))->toBe(Role::Admin);
});
