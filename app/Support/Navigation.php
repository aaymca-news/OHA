<?php

namespace App\Support;

use App\Models\AssessmentMilestone;
use App\Models\MovementStatus;
use App\Models\User;
use App\Queries\MyWork;
use Illuminate\Support\Facades\Gate;

/**
 * The sidebar, decided in one place from each user's role and permissions.
 * Badges count only work this user can act on.
 */
final class Navigation
{
    public function __construct(private readonly MyWork $myWork) {}

    /**
     * @return list<array{label: string, route: string, params: array<string, mixed>, icon: string, badge: int, tone: string, active: string}>
     */
    public function for(User $user): array
    {
        $item = fn (string $key, string $route, string $icon, int $badge = 0, string $tone = 'critical', array $params = [], ?string $active = null) => [
            'label' => __('oha.nav.'.$key), 'route' => $route, 'params' => $params, 'icon' => $icon,
            'badge' => $badge, 'tone' => $tone, 'active' => $active ?? $route.'*',
        ];

        $items = [
            $item('dashboard', 'dashboard', 'dashboard'),
            $item('my_work', 'my-work', 'inbox', $this->myWork->actionableCount($user)),
        ];

        if (! $user->isSecretariat()) {
            $items[] = $item('our_movement', 'movements.show', 'flag', params: ['movement' => $user->movement], active: 'movements.*');

            return $items;
        }

        $items[] = $item('movements', 'movements.index', 'public',
            MovementStatus::query()->where('reassessment_overdue', true)->count(), 'warning', active: 'movements.*');
        $items[] = $item('assessments', 'assessments.index', 'fact_check', active: 'assessments.*');

        if ($user->isAssessor()) {
            $items[] = $item('timelines', 'timelines', 'event_note', $this->overdueMilestones($user));
        }

        if (Gate::forUser($user)->allows('manage', User::class)) {
            $items[] = $item('users', 'admin.users.index', 'manage_accounts', active: 'admin.users.*');
        }

        return $items;
    }

    /** Overdue gate milestones on the assessments this user can act on. */
    private function overdueMilestones(User $user): int
    {
        return AssessmentMilestone::query()
            ->where('overdue', true)
            ->whereHas('assessment', fn ($q) => $q->whereHas('workItem')
                ->when(! $user->oversees(), fn ($q) => $q->whereIn('movement_id', $user->assignedMovements()->select('movements.id'))))
            ->count();
    }
}
