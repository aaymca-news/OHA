<?php

namespace App\Queries;

use App\Enums\TaskProgress;
use App\Models\Assessment;
use App\Models\OdpTask;
use App\Oha\FollowUpSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How an ODP is being carried out, in one place: for one assessment (the movement page,
 * its dashboard) and for every movement side by side (the Administrators' dashboard).
 * Each task stands as it was set at the last follow-up meeting (decided 12 Nov 2026).
 */
final class OdpProgress
{
    /**
     * @param  Collection<int, OdpTask>  $tasks
     * @return array{total: int, done: int, in_progress: int, blocked: int, pct: int}
     */
    public static function summary(Collection $tasks): array
    {
        $total = $tasks->count();
        $done = $tasks->where('done', true)->count();

        return [
            'total' => $total,
            'done' => $done,
            'in_progress' => $tasks->filter(fn (OdpTask $t) => ! $t->done && $t->progress === TaskProgress::InProgress)->count(),
            'blocked' => $tasks->filter(fn (OdpTask $t) => ! $t->done && $t->progress === TaskProgress::Blocked)->count(),
            'pct' => $total > 0 ? (int) round($done * 100 / $total) : 0,
        ];
    }

    /**
     * Each strategic priority's progress, in the ODP's order.
     *
     * @param  Collection<int, OdpTask>  $tasks
     * @return list<array{priority: string, total: int, done: int, in_progress: int, blocked: int, pct: int}>
     */
    public static function byPriority(Collection $tasks): array
    {
        return $tasks->groupBy(fn (OdpTask $t) => $t->priority ?? 'Other tasks')
            ->map(fn (Collection $mine, string $priority) => ['priority' => $priority] + self::summary($mine))
            ->values()->all();
    }

    /**
     * One assessment's ODP: its summary and its priorities.
     *
     * @return array{summary: array{total: int, done: int, in_progress: int, blocked: int, pct: int}, priorities: list<array{priority: string, total: int, done: int, in_progress: int, blocked: int, pct: int}>}
     */
    public static function of(Assessment $assessment): array
    {
        $tasks = $assessment->odpTasks()->get();

        return ['summary' => self::summary($tasks), 'priorities' => self::byPriority($tasks)];
    }

    /**
     * Every movement's newest ODP side by side: its progress, its next follow-up, whether
     * its follow-up is paused, and whether it is falling behind (a follow-up past its agreed
     * date and not closed, and not paused). Falling behind first, then the most blocked,
     * then the least done.
     *
     * @param  Collection<int, Assessment>  $assessments  from OdpTimeline::assessments()
     * @return list<array{assessment: Assessment, summary: array{total: int, done: int, in_progress: int, blocked: int, pct: int}, closed: bool, meeting: array{number: int, due_on: Carbon|null, overdue: bool}|null, paused: bool, behind: bool}>
     */
    public static function alliance(Collection $assessments): array
    {
        $tasks = OdpTask::query()->whereIn('assessment_id', $assessments->pluck('id'))->get()->groupBy('assessment_id');

        return $assessments->map(function (Assessment $a) use ($tasks): array {
            $summary = self::summary($tasks[$a->id] ?? collect());
            $closed = ! $a->isUnderWay();
            $next = $closed ? FollowUpSchedule::next($a) : null;
            $meeting = $next !== null ? ['number' => $next['number'], 'due_on' => $next['due_on'], 'overdue' => $next['overdue']] : null;
            $paused = $a->movement->followUpPaused();

            return [
                'assessment' => $a,
                'summary' => $summary,
                'closed' => $closed,
                'meeting' => $meeting,
                'paused' => $paused,
                // Falling behind: in follow-up (closed), not paused, a follow-up past its agreed date.
                'behind' => $closed && ! $paused && ($meeting['overdue'] ?? false),
            ];
        })->filter(fn (array $r) => $r['summary']['total'] > 0)
            ->sort(fn (array $a, array $b) => [$b['behind'], $b['summary']['blocked'], $a['summary']['pct']] <=> [$a['behind'], $a['summary']['blocked'], $b['summary']['pct']])
            ->values()->all();
    }
}
