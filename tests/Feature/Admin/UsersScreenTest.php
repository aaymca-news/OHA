<?php

use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;
use Database\Seeders\MovementsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(MovementsSeeder::class);
    $this->superAdmin = User::factory()->superAdmin()->create(['name' => 'Achieng Odhiambo']);
    $this->admin = User::factory()->admin()->create(['name' => 'Gloria Anyika']);
    $this->staff = User::factory()->create(['name' => 'Tendai Moyo']);
    $this->zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
});

dataset('admin routes', [
    'list' => ['get', 'admin.users.index', false],
    'invite form' => ['get', 'admin.users.create', false],
    'invite' => ['post', 'admin.users.store', false],
    'edit' => ['get', 'admin.users.edit', true],
    'update' => ['put', 'admin.users.update', true],
    'role' => ['put', 'admin.users.role', true],
    'deactivate' => ['post', 'admin.users.deactivate', true],
    'reactivate' => ['post', 'admin.users.reactivate', true],
    'invitation' => ['post', 'admin.users.invitation', true],
]);

it('lets no one but the Administrators into Users & Roles', function (string $method, string $route, bool $needsUser) {
    $url = $needsUser ? route($route, $this->staff) : route($route);

    foreach ([$this->staff, User::factory()->chair($this->zambia)->create()] as $notAdmin) {
        $this->actingAs($notAdmin)->{$method}($url)->assertForbidden();
    }
})->with('admin routes');

it('shows the Administrators the Secretariat and the Board Chairpersons', function () {
    $chair = User::factory()->chair($this->zambia)->create(['name' => 'Chanda Mwale']);

    foreach ([$this->admin, $this->superAdmin] as $admin) {
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertSee('Tendai Moyo')->assertDontSee('Chanda Mwale');
        $this->actingAs($admin)->get(route('admin.users.index', ['show' => 'boards']))->assertOk()->assertSee('Chanda Mwale')->assertSee('Board Chairperson');
        $this->actingAs($admin)->get(route('admin.users.edit', $chair))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk();
    }
});

it('offers the Administrator roles only to the Super Administrator', function () {
    $this->actingAs($this->admin)->get(route('admin.users.create'))->assertOk()
        ->assertDontSee('value="admin"', false)->assertDontSee('value="super_admin"', false)
        ->assertSee('Only the Super Administrator gives the Administrator roles.');

    $this->actingAs($this->superAdmin)->get(route('admin.users.create'))->assertOk()
        ->assertSee('value="admin"', false)->assertSee('value="super_admin"', false);
});

it('saves a user’s details and the movements they assess', function () {
    $this->actingAs($this->admin)->put(route('admin.users.update', $this->staff), [
        'name' => 'Tendai Moyo', 'email' => 'TENDAI@aaymca.test', 'title' => 'Zonal Coordinator, Southern Africa',
        'movement_ids' => [$this->zambia->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->staff->refresh()->email)->toBe('tendai@aaymca.test')
        ->and($this->staff->assignedMovements->pluck('slug')->all())->toBe(['zambia']);
});

it('changes a role through the screen, and never the user’s own', function () {
    $this->actingAs($this->superAdmin)->put(route('admin.users.role', $this->staff), ['role' => 'admin'])->assertSessionHasNoErrors();
    expect($this->staff->refresh()->role)->toBe(Role::Admin);

    $this->actingAs($this->superAdmin)->put(route('admin.users.role', $this->superAdmin), ['role' => 'staff'])
        ->assertSessionHasErrors(['action' => 'You cannot change your own role.']);
    expect($this->superAdmin->refresh()->role)->toBe(Role::SuperAdmin);
});

it('keeps an Administrator from giving or taking away the Administrator roles', function () {
    $this->actingAs($this->admin)->put(route('admin.users.role', $this->staff), ['role' => 'admin'])
        ->assertSessionHasErrors(['action' => 'Only the Super Administrator gives the Administrator roles.']);
    $this->actingAs($this->admin)->put(route('admin.users.role', $this->superAdmin), ['role' => 'staff'])
        ->assertSessionHasErrors(['action' => 'Only the Super Administrator manages an Administrator’s account.']);
    $this->actingAs($this->admin)->post(route('admin.users.deactivate', $this->superAdmin))
        ->assertSessionHasErrors(['action' => 'Only the Super Administrator manages an Administrator’s account.']);

    expect($this->staff->refresh()->role)->toBe(Role::Staff)
        ->and($this->superAdmin->refresh()->active)->toBeTrue();
});

it('makes someone a Board Chairperson, replacing the current one only when confirmed', function () {
    $current = User::factory()->chair($this->zambia)->create(['name' => 'Naledi Moyo']);

    $this->actingAs($this->admin)->put(route('admin.users.role', $this->staff), ['role' => 'board', 'board_movement_id' => $this->zambia->id])
        ->assertSessionHasErrors(['action' => 'Naledi Moyo is Zambia YMCA’s Board Chairperson, and a movement has one. Confirm to replace them: their account will be deactivated.']);

    $this->actingAs($this->admin)->put(route('admin.users.role', $this->staff), ['role' => 'board', 'board_movement_id' => $this->zambia->id, 'confirm_replace' => '1'])
        ->assertSessionHasNoErrors();

    expect($this->staff->refresh()->role)->toBe(Role::Board)
        ->and($this->zambia->chair()->first()->id)->toBe($this->staff->id)
        ->and($current->refresh()->active)->toBeFalse();
});

it('deactivates a user, signs them out everywhere, and never the Administrator themself', function () {
    DB::table('sessions')->insert(['id' => 'tendai-phone', 'user_id' => $this->staff->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($this->admin)->post(route('admin.users.deactivate', $this->staff))->assertSessionHasNoErrors();

    expect($this->staff->refresh()->active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $this->staff->id)->count())->toBe(0);

    $this->actingAs($this->admin)->post(route('admin.users.deactivate', $this->admin))
        ->assertSessionHasErrors(['action' => 'You cannot deactivate your own account.']);
});

it('will not reactivate a former Chairperson while their movement has another', function () {
    $former = User::factory()->chair($this->zambia)->inactive()->create();
    User::factory()->chair($this->zambia)->create(['name' => 'Naledi Moyo']);

    $this->actingAs($this->admin)->post(route('admin.users.reactivate', $former))
        ->assertSessionHasErrors(['action' => 'Naledi Moyo is now Zambia YMCA’s Board Chairperson, and a movement has one. Deactivate them first.']);
    expect($former->refresh()->active)->toBeFalse();
});

it('re-sends an invitation only to someone who has not yet accepted one', function () {
    $pending = User::factory()->invitationPending()->create();

    $this->actingAs($this->admin)->post(route('admin.users.invitation', $pending))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.users.invitation', $this->staff))->assertSessionHasErrors('action');
});
