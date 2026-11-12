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
 * Stage 2 done: the follow-up cycle is closed once its overall review is written (what
 * was achieved, and what was not and why: the gaps the next assessment takes up).
 *
 *   - once its three follow-ups are closed: by an assessor on the movement, or an
 *     Administrator (decided 12 Nov 2026);
 *   - before that ("early"): only by an Administrator, who says why (kept). The follow-ups
 *     not closed are then "Not held".
 *
 * Settling every unfinished task is not required: tasks unfinished stay on the record
 * (and can still be carried into the next assessment). The follow-up is then read-only
 * history, and the movement is "Cycle complete · ready for the next assessment". The
 * General Secretary and the assessors are told.
 */
final class CloseFollowUp
{
    use EnforcesPolicy;

    public function handle(Assessment $assessment, User $by, ?string $earlyReason = null): Assessment
    {
        $this->ensure($by, 'closeFollowUp', $assessment);
        $missing = self::missing($assessment);
        if ($missing !== []) {
            throw new WorkflowRuleBroken('The cycle cannot close yet: '.implode('; ', $missing).'.');
        }
        $early = self::isEarly($assessment);
        $reason = $earlyReason !== null && trim($earlyReason) !== '' ? trim($earlyReason) : null;
        if ($early && ! $by->isAdmin()) {
            throw new WorkflowRuleBroken('Close the three follow-ups first. Only an Administrator can close the cycle before them, with a reason.');
        }
        if ($early && $reason === null) {
            throw new WorkflowRuleBroken('Say why the cycle closes before its three follow-ups are closed.');
        }

        $leftOpen = self::leftOpen($assessment);
        DB::transaction(function () use ($assessment, $by, $leftOpen, $early, $reason): void {
            $assessment->newQuery()->whereKey($assessment->id)->lockForUpdate()->first();
            $assessment->forceFill([
                'follow_up_closed_at' => now(), 'follow_up_closed_by' => $by->id, 'follow_up_closed_reason' => $early ? $reason : null,
            ])->save();
            Audit::record($by, 'follow_up.closed', $assessment, $assessment, payload: array_filter(['left_open' => $leftOpen, 'early_reason' => $early ? $reason : null]));
        });

        $movement = $assessment->movement;
        Notify::send(
            collect([$movement->generalSecretary()->first()])->merge(Notify::assessorsOf($assessment)),
            new WorkflowNotice(
                "ODP cycle complete: {$movement->name}",
                "{$by->name} closed the ODP follow-up of {$movement->name} ({$assessment->period_label})"
                    .($leftOpen !== [] ? ' with '.implode(' and ', $leftOpen) : '')
                    .($early ? ', before its three follow-ups: '.$reason : '')
                    .'. It stays as a record; the movement is ready for its next assessment, which takes up what is still open.',
                route('follow-up.show', $assessment, false), 'good',
            ),
            except: $by,
        );

        return $assessment->refresh();
    }

    /**
     * What stands in the way of closing, in words (empty: it can close): the overall review.
     * Closing before the three follow-ups are closed is a separate matter (isEarly()).
     *
     * @return list<string>
     */
    public static function missing(Assessment $assessment): array
    {
        return $assessment->odpReview()->exists() ? [] : ['the overall review is not written'];
    }

    /** Whether closing now would be early: not all its follow-ups are closed (only an Administrator may, with a reason). */
    public static function isEarly(Assessment $assessment): bool
    {
        $schedule = FollowUpSchedule::for($assessment);

        return collect($schedule)->where('state', FollowUpSchedule::LOCKED)->count() < count($schedule);
    }

    /**
     * What is left open when closing now, in words, to say so before closing: follow-ups not
     * closed, unfinished tasks (they stay on the record). Empty: nothing.
     *
     * @return list<string>
     */
    public static function leftOpen(Assessment $assessment): array
    {
        $open = [];
        $schedule = FollowUpSchedule::for($assessment);
        $locked = collect($schedule)->where('state', FollowUpSchedule::LOCKED)->count();
        if ($locked < count($schedule)) {
            $open[] = $locked.' of '.count($schedule).' follow-ups closed';
        }
        $unfinished = $assessment->odpTasks()->get()->reject->isSettled()->count();
        if ($unfinished > 0) {
            $open[] = $unfinished.' '.($unfinished === 1 ? 'task' : 'tasks').' not done';
        }

        return $open;
    }
}
