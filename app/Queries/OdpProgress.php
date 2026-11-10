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
 * Done counts only once confirmed (Stage 2).
 */
final class OdpProgress
{
    /**
     * @param  Collection<int, OdpTask>  $tasks
     * @return array{total: int, done: int, waiting: int, overdue: int, blocked: int, pct: int}
     */
    public static function summary(Collection $tasks): array
    {
        $total = $tasks->count();
        $done = $tasks->where('done', true)->count();

        return [
            'total' => $total,
            'done' => $done,
            'waiting' => $tasks->filter(fn (OdpTask $t) => $t->awaitsConfirmation())->count(),
            'overdue' => $tasks->filter(fn (OdpTask $t) => $t->state() === 'overdue')->count(),
            'blocked' => $tasks->filter(fn (OdpTask $t) => ! $t->done && $t->progress === TaskProgress::Blocked)->count(),
            'pct' => $total > 0 ? (int) round($done * 100 / $total) : 0,
        ];
    }

    /**
     * Each strategic priority's progress, in the ODP's order.
     *
     * @param  Collection<int, OdpTask>  $tasks
     * @return list<array{priority: string, total: int, done: int, waiting: int, overdue: int, blocked: int, pct: int}>
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
     * @return array{summary: array{total: int, done: int, waiting: int, overdue: int, blocked: int, pct: int}, priorities: list<array{priority: string, total: int, done: int, waiting: int, overdue: int, blocked: int, pct: int}>}
     */
    public static function of(Assessment $assessment): array
    {
        $tasks = $assessment->odpTasks()->get();

        return ['summary' => self::summary($tasks), 'priorities' => self::byPriority($tasks)];
    }

    /**
     * Every movement's newest ODP side by side: its progress, its next follow-up meeting,
     * whether its follow-up is paused, and whether it is falling behind (tasks or a
     * meeting overdue, and not paused). Falling behind first, then the least done.
     *
     * @param  Collection<int, Assessment>  $assessments  from OdpTimeline::assessments()
     * @return list<array{assessment: Assessment, summary: array{total: int, done: int, waiting: int, overdue: int, blocked: int, pct: int}, signed: bool, meeting: array{number: int, due_on: Carbon, overdue: bool}|null, paused: bool, behind: bool}>
     */
    public static function alliance(Collection $assessments): array
    {
        $tasks = OdpTask::query()->whereIn('assessment_id', $assessments->pluck('id'))->get()->groupBy('assessment_id');

        return $assessments->map(function (Assessment $a) use ($tasks): array {
            $summary = self::summary($tasks[$a->id] ?? collect());
            $signed = ! $a->isUnderWay();
            $next = $signed ? FollowUpSchedule::next($a) : null;
            $meeting = $next !== null ? ['number' => $next['number'], 'due_on' => $next['due_on'], 'overdue' => $next['due_on']->lt(today())] : null;
            $paused = $a->movement->followUpPaused();

            return [
                'assessment' => $a,
                'summary' => $summary,
                'signed' => $signed,
                'meeting' => $meeting,
                'paused' => $paused,
                // Falling behind: in follow-up (signed), not paused, with tasks or a meeting overdue.
                'behind' => $signed && ! $paused && ($summary['overdue'] > 0 || ($meeting['overdue'] ?? false)),
            ];
        })->filter(fn (array $r) => $r['summary']['total'] > 0)
            ->sort(fn (array $a, array $b) => [$b['behind'], $b['summary']['overdue'], $a['summary']['pct']] <=> [$a['behind'], $a['summary']['overdue'], $b['summary']['pct']])
            ->values()->all();
    }
}
