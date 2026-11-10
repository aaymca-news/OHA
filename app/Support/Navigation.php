<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\AssessmentMilestone;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\OdpTask;
use App\Models\User;
use App\Queries\MyWork;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * The sidebar, decided in one place from each user's role and permissions.
 * Badges count only work this user can act on.
 */
final class Navigation
{
    public function __construct(private readonly MyWork $myWork) {}

    /**
     * The sidebar for one person. Its badges count work across the platform, so they are
     * kept for oha.badge_cache_seconds (a minute) and worked out afresh as soon as anything
     * is recorded in the audit trail (every change of work is): see forget().
     *
     * @return list<array{label: string, route: string, params: array<string, mixed>, icon: string, badge: int, tone: string, active: string, group: ?string}>
     */
    public function for(User $user): array
    {
        $seconds = (int) config('oha.badge_cache_seconds');
        if ($seconds <= 0) {
            return $this->build($user);
        }

        // "v2": entries cached before the sidebar held only plain values are never read back.
        return Cache::remember('nav:v3:'.Cache::get('nav:version', 0).':'.$user->id, $seconds, fn () => $this->build($user));
    }

    /** Every sidebar is worked out afresh on its next page: something changed. */
    public static function forget(): void
    {
        Cache::add('nav:version', 0);
        Cache::increment('nav:version');
    }

    /**
     * @return list<array{label: string, route: string, params: array<string, mixed>, icon: string, badge: int, tone: string, active: string, group: ?string}>
     */
    private function build(User $user): array
    {
        $item = fn (string $key, string $route, string $icon, int $badge = 0, string $tone = 'critical', array $params = [], ?string $active = null, ?string $group = null) => [
            'label' => __('oha.nav.'.$key), 'route' => $route, 'params' => $params, 'icon' => $icon,
            'badge' => $badge, 'tone' => $tone, 'active' => $active ?? $route.'*', 'group' => $group,
        ];

        $items = [$item('dashboard', 'dashboard', 'dashboard')];

        // A National Movement's own users. The Board Chairperson has the ODP to sign, in
        // My Work; the National General Secretary has no work to act on. Both read their
        // movement's approved documents in Resources, where the NGS also adds evidence.
        if (! $user->isSecretariat()) {
            if (self::hasMyWork($user)) {
                $items[] = $item('my_work', 'my-work', 'inbox', $this->myWork->actionableCount($user));
            }
            // Plain values only (its slug, not the model): the sidebar is cached, and a model
            // read back from the cache cannot be rebuilt (it came back incomplete: a 500).
            $items[] = $item('our_movement', 'movements.show', 'flag', params: ['movement' => $user->movement?->getRouteKey()], active: 'movements.*');
            $items[] = $item('resources', 'resources', 'folder_open');

            return $items;
        }

        // AAYMCA: staff see their own work; the Administrators also hand out the
        // assessments (Assessments) and manage the users (Users & Roles).
        $items[] = $item('my_work', 'my-work', 'inbox', $this->myWork->actionableCount($user));
        $items[] = $item('movements', 'movements.index', 'public',
            // Reassessments overdue, apart from movements whose follow-up is paused.
            MovementStatus::query()->where('reassessment_overdue', true)
                ->whereHas('movement', fn ($m) => $m->where(fn ($q) => $q->whereNull('follow_up_paused_until')->orWhere('follow_up_paused_until', '<', today())))
                ->count(), 'warning', active: 'movements.*', group: 'movements');
        if ($user->isAdmin()) {
            $items[] = $item('assessments', 'assessments.index', 'fact_check',
                $this->unassigned(), 'warning', active: 'assessments.*', group: 'movements');
        }
        $items[] = $item('timelines', 'timelines', 'event_note', $this->overdue($user), group: 'movements');
        $items[] = $item('resources', 'resources', 'folder_open', group: 'library');

        if (Gate::forUser($user)->allows('manage', User::class)) {
            $items[] = $item('users', 'admin.users.index', 'manage_accounts', active: 'admin.users.*', group: 'admin');
        }

        return $items;
    }

    /** Everyone has My Work except the National General Secretary, who has no work in the platform yet. */
    public static function hasMyWork(User $user): bool
    {
        return $user->role !== Role::Ngs;
    }

    /** Movements with an assessment under way, or never assessed, that nobody is assessing. */
    private function unassigned(): int
    {
        return Movement::query()->whereDoesntHave('assessors', fn ($q) => $q->where('active', true))
            ->where(fn ($q) => $q->whereHas('assessments', fn ($a) => $a->underWay())->orWhereDoesntHave('assessments'))
            ->count();
    }

    /** Overdue gate milestones and ODP tasks on the movements this user may see on Timelines. */
    private function overdue(User $user): int
    {
        $odps = OdpTimeline::assessments($user->visibleMovementIds());

        return $this->overdueMilestones($user) + OdpTask::query()
            ->whereIn('assessment_id', $odps->pluck('id'))
            ->where('done', false)->whereNotNull('due_on')->where('due_on', '<', today())
            ->count();
    }

    /** Overdue gate milestones on the assessments this user can act on. */
    private function overdueMilestones(User $user): int
    {
        return AssessmentMilestone::query()
            ->where('overdue', true)
            ->whereHas('assessment', fn ($q) => $q->underWay()
                ->when(! $user->oversees(), fn ($q) => $q->whereIn('movement_id', $user->assignedMovements()->select('movements.id'))))
            ->count();
    }
}
