<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\AssessmentMilestone;
use App\Models\FollowUpWindow;
use App\Models\Movement;
use App\Models\MovementStatus;
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
     * @return list<array{label: string, route: string, params: array<string, mixed>, icon: string, badge: int, tone: string, active: string|list<string>, group: ?string}>
     */
    public function for(User $user): array
    {
        $seconds = (int) config('oha.badge_cache_seconds');
        if ($seconds <= 0) {
            return $this->build($user);
        }

        // "v2": entries cached before the sidebar held only plain values are never read back.
        return Cache::remember('nav:v5:'.Cache::get('nav:version', 0).':'.$user->id, $seconds, fn () => $this->build($user));
    }

    /** Every sidebar is worked out afresh on its next page: something changed. */
    public static function forget(): void
    {
        Cache::add('nav:version', 0);
        Cache::increment('nav:version');
    }

    /**
     * @return list<array{label: string, route: string, params: array<string, mixed>, icon: string, badge: int, tone: string, active: string|list<string>, group: ?string}>
     */
    private function build(User $user): array
    {
        $item = fn (string $key, string $route, string $icon, int $badge = 0, string $tone = 'critical', array $params = [], string|array|null $active = null, ?string $group = null) => [
            'label' => __('oha.nav.'.$key), 'route' => $route, 'params' => $params, 'icon' => $icon,
            'badge' => $badge, 'tone' => $tone, 'active' => $active ?? $route.'*', 'group' => $group,
        ];

        // A National Movement's own users. Their dashboard is their movement's page (since
        // 23 Nov 2026), so it stays lit there too. The Board Chairperson may sign the ODP, in
        // My Work; the National General Secretary has no work to act on. Both read their
        // movement's approved documents in Resources, where the NGS also adds evidence.
        if (! $user->isSecretariat()) {
            $items = [$item('dashboard', 'dashboard', 'dashboard', active: ['dashboard', 'movements.*'])];
            if (self::hasMyWork($user)) {
                $items[] = $item('my_work', 'my-work', 'inbox', $this->myWork->actionableCount($user));
            }
            $items[] = $item('resources', 'resources', 'folder_open');

            return $items;
        }

        $items = [$item('dashboard', 'dashboard', 'dashboard')];

        // AAYMCA: staff see their own work; the Administrators also hand out the
        // assessments (Assessments) and manage the users (Users & Roles).
        $items[] = $item('my_work', 'my-work', 'inbox', $this->myWork->actionableCount($user));
        $items[] = $item('movements', 'movements.index', 'public',
            // First assessments past the month an Administrator planned (no band-based dates since 12 Nov 2026).
            MovementStatus::query()->where('reassessment_overdue', true)
                ->whereHas('movement', fn ($m) => $m->where(fn ($q) => $q->whereNull('follow_up_paused_until')->orWhere('follow_up_paused_until', '<', today())))
                ->count(), 'warning', active: 'movements.*', group: 'movements');
        if ($user->isAdmin()) {
            $items[] = $item('assessments', 'assessments.index', 'fact_check',
                $this->unassigned(), 'warning', active: 'assessments.*', group: 'movements');
        }
        $items[] = $item('timelines', 'timelines', 'event_note', $this->overdue($user), group: 'movements');
        // Each movement's latest cycle, baseline by baseline (all staff: analytics, like the dashboard).
        $items[] = $item('journeys', 'journeys.index', 'trending_up', group: 'movements');
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

    /**
     * Overdue gate milestones, and follow-ups past their agreed date and not closed, on the
     * movements this user may see on Timelines (ODP tasks have no deadlines of their own).
     */
    private function overdue(User $user): int
    {
        $mine = $user->visibleMovementIds();

        return $this->overdueMilestones($user) + FollowUpWindow::query()
            ->whereNull('locked_at')->whereNotNull('due_on')->where('due_on', '<', today())
            ->whereHas('assessment', fn ($q) => $q->whereNotNull('closed_at')->whereNull('follow_up_closed_at')
                ->when($mine !== null, fn ($q) => $q->whereIn('movement_id', $mine)))
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
