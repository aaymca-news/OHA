<?php

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/*
 * A new server has no users, and everyone else is invited by an Administrator, so the
 * first Super Administrator is created on the server with a command.
 */

/** Runs the command and returns the set-password link it printed. */
function createSuperAdmin(string $email, string $name): string
{
    expect(Artisan::call('oha:create-super-admin', ['email' => $email, 'name' => $name]))->toBe(0);
    preg_match('#(https?://\S+/invitation/\S+)#', Artisan::output(), $m);

    return $m[1] ?? '';
}

it('creates the first Super Administrator, whose printed link sets their password and signs them in', function () {
    $link = createSuperAdmin('Raymond@AfricaYMCA.org', 'Raymond Njiru');
    $user = User::query()->where('email', 'raymond@africaymca.org')->firstOrFail();
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
    $token = basename((string) parse_url($link, PHP_URL_PATH));

    expect($user->role)->toBe(Role::SuperAdmin)
        ->and($user->isInvitationPending())->toBeTrue()
        ->and($query['email'])->toBe('raymond@africaymca.org')
        ->and(AuditEvent::query()->where('action', 'user.created_on_server')->exists())->toBeTrue();

    $this->get($link)->assertOk()->assertSee('Set your password');
    $this->post(route('invitation.store'), [
        'token' => $token, 'email' => 'raymond@africaymca.org',
        'password' => 'Nairobi-Office-2026', 'password_confirmation' => 'Nairobi-Office-2026',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('gives the same person a fresh link when run again, and refuses once a Super Administrator exists', function () {
    createSuperAdmin('raymond@africaymca.org', 'Raymond Njiru');

    expect(createSuperAdmin('raymond@africaymca.org', 'Raymond Njiru'))->toContain('/invitation/')
        ->and(User::query()->count())->toBe(1);

    expect(Artisan::call('oha:create-super-admin', ['email' => 'someone.else@africaymca.org', 'name' => 'Someone Else']))->toBe(1)
        ->and(Artisan::output())->toContain('There is already a Super Administrator (raymond@africaymca.org)')
        ->and(User::query()->count())->toBe(1);
});

it('refuses an email that is not an email, or that belongs to someone with another role', function () {
    User::factory()->create(['email' => 'staff@africaymca.org']);

    expect(Artisan::call('oha:create-super-admin', ['email' => 'not-an-email', 'name' => 'Raymond Njiru']))->toBe(1)
        ->and(Artisan::call('oha:create-super-admin', ['email' => 'staff@africaymca.org', 'name' => 'Staff Member']))->toBe(1)
        ->and(Artisan::output())->toContain('already has an account as');
});
