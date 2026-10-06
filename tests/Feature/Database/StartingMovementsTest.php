<?php

use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\User;
use Database\Seeders\MovementsSeeder;

/*
 * A new installation starts with the 23 movements and nothing else: no ratings, no
 * assessments, and no planned assessment until someone actually schedules one.
 */

it('starts every movement unscheduled, and says so', function () {
    $this->seed(MovementsSeeder::class);

    expect(Movement::query()->count())->toBe(23)
        ->and(Movement::query()->whereNotNull('planned_assessment_label')->orWhereNotNull('planned_assessment_on')->count())->toBe(0)
        ->and(MovementStatus::query()->whereNotNull('next_assessment_on')->count())->toBe(0);

    $this->actingAs(User::factory()->admin()->create())->get(route('movements.index'))->assertOk()
        ->assertDontSee('planned Qtr')->assertDontSee('overdue')
        ->assertSeeText('Not scheduled');
});
