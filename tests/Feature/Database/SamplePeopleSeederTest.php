<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\MovementsSeeder;
use Database\Seeders\SamplePeopleSeeder;

it('creates the walkthrough users: a Super Administrator, two Administrators, staff and one Chairperson per movement', function () {
    $this->seed([MovementsSeeder::class, SamplePeopleSeeder::class]);

    expect(User::query()->where('role', Role::SuperAdmin)->count())->toBe(1)
        ->and(User::query()->where('role', Role::Admin)->count())->toBe(2)
        ->and(User::query()->where('role', Role::Board)->count())->toBe(23)
        ->and(User::query()->where('role', Role::Board)->distinct()->count('movement_id'))->toBe(23)
        ->and(User::query()->where('email', 'tendai.moyo@aaymca.test')->firstOrFail()->assignedMovements->pluck('slug'))
        ->toContain('zambia');
});

it('refuses to run in production', function () {
    $this->seed(MovementsSeeder::class);
    app()['env'] = 'production';

    expect(fn () => app(SamplePeopleSeeder::class)->run())->toThrow(RuntimeException::class, 'must not run in production');
});
