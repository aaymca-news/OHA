<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;

/**
 * The roles the workflow cannot run without: a Super Administrator (who appoints
 * the Administrators), an Administrator of either kind (who approves; their own work
 * too), and for every movement a National General Secretary, who supplies its evidence.
 * Behind the warning banner the Administrators see on every page. A Board Chairperson
 * is not among them: their signature is optional since 16 Nov 2026 (closing the
 * assessment ends Stage 1), so a movement without one is not flagged, and nobody is
 * reminded by email any more.
 */
final class SystemHealth
{
    /**
     * @return array{ok: bool, severity: string|null, problems: list<string>, movements_without_gs: list<string>}
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

        $withoutGs = Movement::query()->whereDoesntHave('generalSecretary')->orderBy('name')->pluck('name')->all();

        if ($withoutGs !== []) {
            $problems[] = count($withoutGs) === 1
                ? "{$withoutGs[0]} has no National General Secretary, so it cannot supply its own evidence."
                : count($withoutGs).' movements have no National General Secretary, so they cannot supply their own evidence.';
            $severity ??= 'serious';
        }

        return ['ok' => $problems === [], 'severity' => $severity, 'problems' => $problems, 'movements_without_gs' => $withoutGs];
    }

    /** Who sees the warning: those who oversee the platform, the Administrators who appoint. */
    public static function shouldWarn(User $user): bool
    {
        return $user->oversees();
    }
}
