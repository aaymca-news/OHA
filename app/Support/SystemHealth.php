<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The roles the workflow cannot run without: a Super Administrator (who appoints
 * the Administrators), an Administrator of either kind (who approves; their own work
 * too), and a Board Chairperson for every movement, who signs its ODP. Behind the
 * warning banner and the daily reminders.
 */
final class SystemHealth
{
    /**
     * @return array{ok: bool, severity: string|null, problems: list<string>, movements_without_chair: list<string>}
     */
    public static function check(): array
    {
        $problems = [];
        $severity = null;

        $administrators = User::query()->whereIn('role', [Role::Admin, Role::SuperAdmin])->where('active', true)->count();

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
                ? "{$withoutChair[0]} has no Board Chairperson, so its ODP cannot be signed."
                : count($withoutChair).' movements have no Board Chairperson, so their ODPs cannot be signed.';
            $severity ??= 'serious';
        }

        return ['ok' => $problems === [], 'severity' => $severity, 'problems' => $problems, 'movements_without_chair' => $withoutChair];
    }

    /**
     * Who is warned and reminded: the Administrators, who appoint.
     *
     * @return Collection<int, User>
     */
    public static function audience(): Collection
    {
        return User::query()->where('active', true)->whereIn('role', [Role::Admin, Role::SuperAdmin])->get();
    }

    public static function shouldWarn(User $user): bool
    {
        return $user->oversees();
    }
}
