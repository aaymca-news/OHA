<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\FollowUpSchedule;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2 done: an assessor on the movement, or an Administrator, closes a signed ODP's
 * follow-up cycle once its overall review is written (what was achieved, and what was
 * not and why: the gaps the next assessment takes up). The follow-up meetings, and
 * settling every unfinished task, are not required: a cycle may close after one meeting,
 * with tasks unfinished, which stay on the record (and can still be carried into the next
 * assessment). The follow-up is then read-only history, and the movement is "Cycle
 * complete · ready for the next assessment". The movement and its assessors are told.
 */
final class CloseFollowUp
{
    use EnforcesPolicy;

    public function handle(Assessment $assessment, User $by): Assessment
    {
        $this->ensure($by, 'closeFollowUp', $assessment);
        $missing = self::missing($assessment);
        if ($missing !== []) {
            throw new WorkflowRuleBroken('The cycle cannot close yet: '.implode('; ', $missing).'.');
        }

        $leftOpen = self::leftOpen($assessment);
        DB::transaction(function () use ($assessment, $by, $leftOpen): void {
            $assessment->newQuery()->whereKey($assessment->id)->lockForUpdate()->first();
            $assessment->forceFill(['follow_up_closed_at' => now(), 'follow_up_closed_by' => $by->id])->save();
            Audit::record($by, 'follow_up.closed', $assessment, $assessment, payload: array_filter(['left_open' => $leftOpen]));
        });

        $movement = $assessment->movement;
        Notify::send(
            collect([$movement->chair()->first(), $movement->generalSecretary()->first()])->merge(Notify::assessorsOf($assessment)),
            new WorkflowNotice(
                "ODP cycle complete: {$movement->name}",
                "{$by->name} closed the ODP follow-up of {$movement->name} ({$assessment->period_label})"
                    .($leftOpen !== [] ? ' with '.implode(' and ', $leftOpen) : '')
                    .'. It stays as a record; the movement is ready for its next assessment, which takes up what is still open.',
                route('follow-up.show', $assessment, false), 'good',
            ),
            except: $by,
        );

        return $assessment->refresh();
    }

    /**
     * What stands in the way of closing, in words (empty: it can close): only the overall review.
     *
     * @return list<string>
     */
    public static function missing(Assessment $assessment): array
    {
        return $assessment->odpReview()->exists() ? [] : ['the overall review is not written'];
    }

    /**
     * What is left open when closing now, in words, to say so before closing: meetings not
     * held, unfinished tasks (they stay on the record). Empty: nothing.
     *
     * @return list<string>
     */
    public static function leftOpen(Assessment $assessment): array
    {
        $open = [];
        $schedule = FollowUpSchedule::for($assessment);
        $held = collect($schedule)->whereNotNull('meeting')->count();
        if ($held < count($schedule)) {
            $open[] = $held.' of '.count($schedule).' follow-up meetings held';
        }
        $unfinished = $assessment->odpTasks()->get()->reject->isSettled()->count();
        if ($unfinished > 0) {
            $open[] = $unfinished.' '.($unfinished === 1 ? 'task' : 'tasks').' not done';
        }

        return $open;
    }
}
