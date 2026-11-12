<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\Evidence;
use App\Models\FollowUpWindow;
use App\Models\OdpFollowUp;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\FollowUpSchedule;
use App\Support\Audit;
use App\Support\DateFormats;
use App\Support\Notify;
use App\Support\OdpTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The three follow-ups of a closed assessment (decided 12 Nov 2026, see FollowUpSchedule).
 * Nothing happens by itself: the movement's assessors, an Administrator or the Super
 * Administrator do each step, in order.
 *
 *   plan    set (or change, or clear) a follow-up's date, as agreed with the movement,
 *           until it is closed
 *   open    open the next follow-up: the movement is told, and adds its evidence for it
 *   close   once its meeting is recorded: locks its meeting record, where every task
 *           stood and the evidence counted in it; the next one's date can be set at once
 *   reopen  an Administrator only: the last one closed, with a reason (kept)
 */
final class FollowUpWindows
{
    use EnforcesPolicy;

    public function __construct(
        private readonly ReviseOdp $revise,
        private readonly RecordBaseline $baselines,
    ) {}

    /** Sets follow-up $number's date (null: not set yet). */
    public function plan(Assessment $assessment, User $by, int $number, ?string $date): FollowUpWindow
    {
        $this->ensure($by, 'runFollowUps', $assessment);
        $slot = $this->slot($assessment, $number);
        if (in_array($slot['state'], [FollowUpSchedule::LOCKED, FollowUpSchedule::SKIPPED], true)) {
            throw new WorkflowRuleBroken("Follow-up {$number} is closed: its date stays as it was.");
        }
        $due = self::date($date);

        $window = DB::transaction(function () use ($assessment, $by, $number, $due, $slot): FollowUpWindow {
            $window = FollowUpWindow::query()->lockForUpdate()->firstOrCreate(['assessment_id' => $assessment->id, 'number' => $number]);
            $window->forceFill(['due_on' => $due?->toDateString(), 'due_set_by' => $by->id])->save();
            Audit::record($by, 'follow_up.date_set', $window, $assessment, payload: [
                'number' => $number, 'from' => $slot['due_on']?->toDateString(), 'to' => $due?->toDateString(),
            ]);

            return $window;
        });
        if ($due !== null) {
            $this->tell($assessment, $by, "Follow-up {$number} planned: {$assessment->movement->name}",
                "{$by->name} set follow-up {$number} of {$assessment->movement->name}’s ODP for {$due->format(DateFormats::DAY)}"
                .($slot['due_on'] !== null ? " (it was {$slot['due_on']->format(DateFormats::DAY)})." : '.'));
        }

        return $window;
    }

    /** Opens the next follow-up: the movement can then add its evidence for it, and its meeting be recorded. */
    public function open(Assessment $assessment, User $by): FollowUpWindow
    {
        $this->ensure($by, 'runFollowUps', $assessment);
        $next = FollowUpSchedule::next($assessment);
        if ($next === null || $next['state'] !== FollowUpSchedule::UPCOMING) {
            throw new WorkflowRuleBroken($next === null ? 'Every follow-up is already closed.' : "Follow-up {$next['number']} is already open.");
        }

        $window = DB::transaction(function () use ($assessment, $by, $next): FollowUpWindow {
            $window = FollowUpWindow::query()->lockForUpdate()->firstOrCreate(['assessment_id' => $assessment->id, 'number' => $next['number']]);
            $window->forceFill(['opened_at' => now(), 'opened_by' => $by->id])->save();
            Audit::record($by, 'follow_up.opened', $window, $assessment, payload: ['number' => $next['number']]);

            return $window;
        });
        $this->tell($assessment, $by, "Follow-up {$next['number']} is open: {$assessment->movement->name}",
            "{$by->name} opened follow-up {$next['number']} of {$assessment->movement->name}’s ODP"
            .($next['due_on'] !== null ? ", planned for {$next['due_on']->format(DateFormats::DAY)}" : '')
            .'. Add the evidence of what was done, including any not added before, and a note on any task: it counts in this follow-up.');

        // A change made to the ODP in Google Drive while no follow-up was open is taken in now.
        $this->revise->adoptNoted($assessment, $by);

        return $window;
    }

    /** Closes (and so locks) follow-up $number once its meeting is recorded; $nextDate plans the one after. */
    public function close(Assessment $assessment, User $by, int $number, ?string $nextDate = null): FollowUpWindow
    {
        $this->ensure($by, 'runFollowUps', $assessment);
        $slot = $this->slot($assessment, $number);
        if ($slot['state'] !== FollowUpSchedule::OPEN) {
            throw new WorkflowRuleBroken($slot['state'] === FollowUpSchedule::LOCKED ? "Follow-up {$number} is already closed." : "Follow-up {$number} is not open yet.");
        }
        if ($slot['meeting'] === null) {
            throw new WorkflowRuleBroken("Record the meeting of follow-up {$number} first: it is what the follow-up keeps.");
        }
        $nextDue = self::date($nextDate);

        $tasks = $assessment->odpTasks()->get();
        $evidence = Evidence::query()->where('assessment_id', $assessment->id)->where('counted_in', $number)->count();
        $window = DB::transaction(function () use ($assessment, $by, $number, $tasks, $evidence, $nextDue): FollowUpWindow {
            $window = FollowUpWindow::query()->lockForUpdate()->firstOrCreate(['assessment_id' => $assessment->id, 'number' => $number]);
            // The ODP version in force as it closes: the plan this follow-up was held on.
            $window->forceFill(['locked_at' => now(), 'locked_by' => $by->id, 'odp_document_id' => OdpTimeline::source($assessment)?->id])->save();
            Audit::record($by, 'follow_up.locked', $window, $assessment, payload: [
                'number' => $number, 'tasks_done' => $tasks->where('done', true)->count(), 'tasks_total' => $tasks->count(), 'evidence_counted' => $evidence,
            ]);
            // Closing it is the sign-off: the answers re-scored during it are Baseline $number + 1.
            $this->baselines->afterFollowUp($assessment, $number, $by);
            if ($nextDue !== null && $number < (int) config('oha.follow_ups')) {
                FollowUpWindow::query()->firstOrCreate(['assessment_id' => $assessment->id, 'number' => $number + 1])
                    ->forceFill(['due_on' => $nextDue->toDateString(), 'due_set_by' => $by->id])->save();
                Audit::record($by, 'follow_up.date_set', $window, $assessment, payload: ['number' => $number + 1, 'to' => $nextDue->toDateString()]);
            }

            return $window;
        });

        $next = FollowUpSchedule::next($assessment);
        $this->tell($assessment, $by, "Follow-up {$number} closed: {$assessment->movement->name}",
            "{$by->name} closed follow-up {$number} of {$assessment->movement->name}’s ODP. Its meeting record, where each task stood and the "
            .$evidence.' '.($evidence === 1 ? 'document' : 'documents').' counted in it are now locked.'
            .match (true) {
                $next === null => ' That was the last follow-up: the cycle can now be closed.',
                $next['due_on'] !== null => " Follow-up {$next['number']} is planned for {$next['due_on']->format(DateFormats::DAY)}.",
                default => " Follow-up {$next['number']}’s date is not set yet.",
            });

        return $window;
    }

    public function reopen(Assessment $assessment, User $by, int $number, string $reason): FollowUpWindow
    {
        $this->ensure($by, 'manageFollowUps', $assessment);
        if (trim($reason) === '') {
            throw new WorkflowRuleBroken('Say why the follow-up is reopened.');
        }
        $last = collect(FollowUpSchedule::for($assessment))->where('state', FollowUpSchedule::LOCKED)->last();
        if ($last === null || $last['number'] !== $number) {
            throw new WorkflowRuleBroken($last === null ? 'No follow-up is closed yet.' : "Only the last follow-up closed (follow-up {$last['number']}) can be reopened.");
        }

        $window = DB::transaction(function () use ($assessment, $by, $number, $reason): FollowUpWindow {
            $window = FollowUpWindow::query()->lockForUpdate()->where('assessment_id', $assessment->id)->where('number', $number)->firstOrFail();
            $window->forceFill(['locked_at' => null, 'locked_by' => null, 'reopened_at' => now(), 'reopened_by' => $by->id, 'reopen_reason' => trim($reason)])->save();
            Audit::record($by, 'follow_up.reopened', $window, $assessment, payload: ['number' => $number, 'reason' => trim($reason)]);
            // The baseline it recorded is set aside (kept); closing it again records a new one.
            $this->baselines->setAside($assessment, $number, $by, trim($reason));

            return $window;
        });
        $this->tell($assessment, $by, "Follow-up {$number} reopened: {$assessment->movement->name}",
            "{$by->name} reopened follow-up {$number} of {$assessment->movement->name}’s ODP: ".trim($reason).'. It can be corrected, then closed again.');

        return $window;
    }

    /** A date as typed (Y-m-d), or null for none. */
    public static function date(?string $date): ?Carbon
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $parsed = Carbon::createFromFormat('Y-m-d', trim($date));
        if ($parsed === null || $parsed->format('Y-m-d') !== trim($date)) {
            throw new WorkflowRuleBroken('Choose a real date for the follow-up.');
        }

        return $parsed->startOfDay();
    }

    /**
     * @return array{number: int, due_on: Carbon|null, meeting: OdpFollowUp|null, window: FollowUpWindow|null, state: string, overdue: bool}
     */
    private function slot(Assessment $assessment, int $number): array
    {
        return collect(FollowUpSchedule::for($assessment))->firstWhere('number', $number)
            ?? throw new WorkflowRuleBroken("There is no follow-up {$number}.");
    }

    private function tell(Assessment $assessment, ?User $by, string $subject, string $body): void
    {
        Notify::send(
            collect([$assessment->movement->generalSecretary()->first()])->merge(Notify::assessorsOf($assessment)),
            new WorkflowNotice($subject, $body, route('follow-up.show', $assessment, false), 'info'),
            except: $by,
        );
    }
}
