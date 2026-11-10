<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\AssessmentMilestone;
use App\Oha\FollowUpSchedule;
use Illuminate\Support\Carbon;

/**
 * A movement's assessment cycle as one line of steps, for x-cycle-timeline: the four
 * Stage 1 gates (form, report, ODP, signing), then Stage 2 (the follow-up meetings and
 * the overall review), then the next assessment. Each step done, next (the first not
 * done) or still to come.
 */
final class MovementCycle
{
    /**
     * @return list<array{title: string, meta: string, state: string, late: bool}>
     */
    public static function steps(?Assessment $assessment, ?Carbon $nextAssessmentOn): array
    {
        $nextOn = $nextAssessmentOn !== null ? 'Due '.$nextAssessmentOn->format(DateFormats::MONTH) : 'To be set by AAYMCA';

        if ($assessment === null) {
            return self::mark([
                ['title' => 'OHA form approved', 'meta' => 'Once your assessment begins', 'done' => false],
                ['title' => 'Report and ODP approved', 'meta' => 'Written with your assessors', 'done' => false],
                ['title' => 'ODP signed by the board', 'meta' => 'By the Board Chairperson', 'done' => false],
                ['title' => 'Follow-up year', 'meta' => 'Meetings and an overall review', 'done' => false],
            ], ['title' => 'Next assessment', 'meta' => $nextOn]);
        }

        $steps = AssessmentMilestone::query()->where('assessment_id', $assessment->id)->orderBy('sort_order')->get()
            ->map(fn (AssessmentMilestone $m) => [
                'title' => $m->gate_code === 'board' ? 'ODP signed by the board' : $m->milestone_label,
                'meta' => $m->done_at !== null ? 'Done '.$m->done_at->format(DateFormats::DAY) : 'Due '.$m->due_on->format(DateFormats::DAY).($m->overdue ? ' · overdue' : ''),
                'done' => $m->done_at !== null,
                'late' => $m->done_at === null && $m->overdue,
            ])->all();

        // Stage 2, once the ODP is signed: the meetings, then the overall review.
        $meetings = FollowUpSchedule::for($assessment);
        $closed = $assessment->isFollowUpClosed();
        $held = collect($meetings)->whereNotNull('meeting')->count();
        $upcoming = collect($meetings)->first(fn (array $m) => $m['meeting'] === null);
        $total = max(count($meetings), (int) config('oha.follow_ups_per_year'));
        $steps[] = [
            'title' => 'Follow-up meetings',
            'meta' => $held.' of '.$total.' held'.(! $closed && $upcoming !== null ? ' · next due '.$upcoming['due_on']->format(DateFormats::DAY) : ''),
            'done' => $closed || ($meetings !== [] && $upcoming === null),
            'late' => ! $closed && $upcoming !== null && $upcoming['due_on']->lt(today()),
        ];
        $review = $meetings !== [] ? $assessment->odpReview()->first() : null;
        $steps[] = [
            'title' => 'Overall review',
            'meta' => $review !== null ? 'Written '.$review->updated_at->format(DateFormats::DAY) : 'Written by your assessors',
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
