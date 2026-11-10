<?php

namespace App\Actions\Movements;

use App\Actions\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\DateFormats;
use App\Support\Notify;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2: an approved pause of a movement's ODP follow-up, for elections or a crisis
 * (an idea from Jake Meacham's MyYMCA app). Until its end date, no follow-up reminders
 * are sent and "Paused" is shown instead of "Overdue". An Administrator agrees it, with
 * a reason, and can lift it early. The movement and its assessors are told.
 */
final class PauseFollowUp
{
    use EnforcesPolicy;

    public function pause(Movement $movement, User $by, string $until, string $reason): Movement
    {
        $this->ensure($by, 'pauseFollowUp', $movement);
        $end = Carbon::parse($until)->startOfDay();
        if ($end->lt(today())) {
            throw new WorkflowRuleBroken('Choose an end date from today on.');
        }
        if (trim($reason) === '') {
            throw new WorkflowRuleBroken('Say why the follow-up is paused (for example, board elections).');
        }

        DB::transaction(function () use ($movement, $by, $end, $reason): void {
            $movement->update(['follow_up_paused_until' => $end->toDateString(), 'follow_up_pause_reason' => trim($reason), 'follow_up_paused_by' => $by->id]);
            Audit::record($by, 'movement.follow_up_paused', $movement, payload: ['until' => $end->toDateString(), 'reason' => trim($reason)]);
        });
        $this->tell($movement, $by, "{$movement->name}’s ODP follow-up is paused",
            "{$by->name} paused the ODP follow-up until {$end->format(DateFormats::DAY)}: ".trim($reason).'. No reminders are sent meanwhile.');

        return $movement->refresh();
    }

    public function resume(Movement $movement, User $by): Movement
    {
        $this->ensure($by, 'pauseFollowUp', $movement);

        DB::transaction(function () use ($movement, $by): void {
            $movement->update(['follow_up_paused_until' => null, 'follow_up_pause_reason' => null, 'follow_up_paused_by' => null]);
            Audit::record($by, 'movement.follow_up_resumed', $movement);
        });
        $this->tell($movement, $by, "{$movement->name}’s ODP follow-up resumes", "{$by->name} lifted the pause: the ODP follow-up and its reminders resume.");

        return $movement->refresh();
    }

    private function tell(Movement $movement, User $by, string $subject, string $body): void
    {
        Notify::send(
            collect([$movement->chair()->first(), $movement->generalSecretary()->first()])->merge($movement->activeAssessors()->get()),
            new WorkflowNotice($subject, $body, route('movements.show', $movement, false), 'info'),
            except: $by,
        );
    }
}
