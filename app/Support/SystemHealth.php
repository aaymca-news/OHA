<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The roles the workflow cannot run without: a Super Administrator (who appoints
 * the Administrators), an Administrator of either kind (who approves; their own work
 * too), and for every movement a Board Chairperson, who signs its ODP, and a National
 * General Secretary, who supplies its evidence. Behind the
 * warning banner and the daily reminders.
 */
final class SystemHealth
{
    /**
     * @return array{ok: bool, severity: string|null, problems: list<string>, movements_without_chair: list<string>, movements_without_gs: list<string>}
     */
    public static function check(): array
    {
        $problems = [];
        $severity = null;

        $administrators = User::query()->administrators()->count();

        if ($administrators === 0) {
            $problems[] = 'There is no Administrator. Nothing can be approved.';
            $severity = 'critical';
        }

        if (! User::query()->where('role', Role::SuperAdmin)->where('active', true)->exists()) {
            $problems[] = 'There is no Super Administrator, so nobody can appoint an Administrator.';
            $severity ??= 'serious';
        }

        $withoutChair = Movement::query()->whereDoesntHave('chair')->orderBy('name')->pluck('name')->all();

        if ($withoutChair !== []) {
            $problems[] = count($withoutChair) === 1
                ? "{$withoutChair[0]} has no Board Chairperson, so nobody can validate its ODP for the board."
                : count($withoutChair).' movements have no Board Chairperson, so nobody can validate their ODPs for the board.';
            $severity ??= 'serious';
        }

        $withoutGs = Movement::query()->whereDoesntHave('generalSecretary')->orderBy('name')->pluck('name')->all();

        if ($withoutGs !== []) {
            $problems[] = count($withoutGs) === 1
                ? "{$withoutGs[0]} has no National General Secretary, so it cannot supply its own evidence."
                : count($withoutGs).' movements have no National General Secretary, so they cannot supply their own evidence.';
            $severity ??= 'serious';
        }

        return ['ok' => $problems === [], 'severity' => $severity, 'problems' => $problems, 'movements_without_chair' => $withoutChair, 'movements_without_gs' => $withoutGs];
    }

    /**
     * Who is warned and reminded: the Administrators, who appoint.
     *
     * @return Collection<int, User>
     */
    public static function audience(): Collection
    {
        return User::query()->administrators()->get();
    }

    public static function shouldWarn(User $user): bool
    {
        return $user->oversees();
    }
}
