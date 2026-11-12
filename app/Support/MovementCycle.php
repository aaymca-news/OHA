<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\AssessmentMilestone;
use App\Oha\FollowUpSchedule;
use Illuminate\Support\Carbon;

/**
 * A movement's assessment cycle as one line of steps, for x-cycle-timeline: the four
 * Stage 1 gates (form, report, ODP, closing), then Stage 2 (the follow-up meetings and
 * the overall review), then the next assessment. Each step done, next (the first not
 * done) or still to come.
 */
final class MovementCycle
{
    /**
     * @param  Carbon|null  $plannedOn  the first assessment an Administrator planned, for a movement never assessed
     * @param  string  $whose  "your" for the movement's own people, "its" for AAYMCA staff
     * @return list<array{title: string, meta: string, state: string, late: bool}>
     */
    public static function steps(?Assessment $assessment, ?Carbon $plannedOn, string $whose = 'your'): array
    {
        // No band-based date (decided 12 Nov 2026): the next assessment starts once the cycle is closed.
        $nextOn = match (true) {
            $assessment === null => $plannedOn !== null ? 'Planned '.$plannedOn->format(DateFormats::MONTH) : 'To be planned by AAYMCA',
            $assessment->isFollowUpClosed() => 'Ready to start',
            default => 'Once this cycle is closed',
        };

        if ($assessment === null) {
            return self::mark([
                ['title' => 'OHA form approved', 'meta' => 'Once '.$whose.' assessment begins', 'done' => false],
                ['title' => 'Report and ODP approved', 'meta' => 'Written with '.$whose.' assessors', 'done' => false],
                ['title' => 'Assessment closed', 'meta' => 'Sets Baseline 1, by '.$whose.' assessors', 'done' => false],
                ['title' => 'Follow-up year', 'meta' => 'Three follow-ups and an overall review', 'done' => false],
            ], ['title' => 'Next assessment', 'meta' => $nextOn]);
        }

        $steps = AssessmentMilestone::query()->where('assessment_id', $assessment->id)->orderBy('sort_order')->get()
            ->map(fn (AssessmentMilestone $m) => [
                'title' => $m->milestone_label,
                'meta' => $m->done_at !== null ? 'Done '.$m->done_at->format(DateFormats::DAY) : 'Due '.$m->due_on->format(DateFormats::DAY).($m->overdue ? ' · overdue' : ''),
                'done' => $m->done_at !== null,
                'late' => $m->done_at === null && $m->overdue,
            ])->all();

        // Stage 2, once the assessment is closed: the follow-ups (each closed and locked in turn), then the overall review.
        $meetings = FollowUpSchedule::for($assessment);
        $closed = $assessment->isFollowUpClosed();
        $locked = collect($meetings)->where('state', FollowUpSchedule::LOCKED)->count();
        $upcoming = FollowUpSchedule::next($assessment);
        $total = max(count($meetings), (int) config('oha.follow_ups'));
        $steps[] = [
            'title' => 'Follow-ups',
            'meta' => $locked.' of '.$total.' closed'.(! $closed && $upcoming !== null
                ? ' · next '.($upcoming['due_on'] !== null ? $upcoming['due_on']->format(DateFormats::DAY) : 'date not set') : ''),
            'done' => $closed || ($meetings !== [] && $upcoming === null),
            'late' => ! $closed && (bool) ($upcoming['overdue'] ?? false),
        ];
        $review = $meetings !== [] ? $assessment->odpReview()->first() : null;
        $steps[] = [
            'title' => 'Overall review',
            'meta' => $review !== null ? 'Written '.$review->updated_at->format(DateFormats::DAY) : 'Written by '.$whose.' assessors',
            'done' => $review !== null || $closed,
        ];

        return self::mark($steps, ['title' => 'Next assessment', 'meta' => $nextOn]);
    }

    /**
     * The first step not done is next; the next assessment closes the line.
     *
     * @param  list<array{title: string, meta: string, done: bool, late?: bool}>  $steps
     * @param  array{title: string, meta: string}  $last
     * @return list<array{title: string, meta: string, state: string, late: bool}>
     */
    private static function mark(array $steps, array $last): array
    {
        $steps[] = $last + ['done' => false];
        $next = collect($steps)->search(fn (array $s) => ! $s['done']);

        return collect($steps)->map(fn (array $s, int $i) => [
            'title' => $s['title'],
            'meta' => $s['meta'],
            'state' => $s['done'] ? 'done' : ($i === $next ? 'next' : 'todo'),
            'late' => (bool) ($s['late'] ?? false),
        ])->all();
    }
}
