<?php

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('shows a user their security settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('security.show'))
        ->assertOk()
        ->assertSee('Where you are signed in')
        ->assertSee('Change password');
});

it('signs the user out everywhere else, after checking their password', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert([
        ['id' => 'laptop', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
    ]);

    $this->actingAs($user)->delete(route('security.other-sessions'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(2);

    $this->actingAs($user)->delete(route('security.other-sessions'), ['password' => 'password'])
        ->assertSessionHas('status', 'Signed out of 2 other sessions.');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'auth.signed_out_elsewhere')->exists())->toBeTrue();
});

it('changes a password only with the current one, to a strong new one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrorsIn('updatePassword', 'password');

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'Lusaka-Office-2026', 'password_confirmation' => 'Lusaka-Office-2026',
    ])->assertSessionHasNoErrors();

    expect(password_verify('Lusaka-Office-2026', $user->refresh()->password))->toBeTrue();
});
