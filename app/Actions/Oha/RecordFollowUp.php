<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Enums\TaskProgress;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\OdpFollowUp;
use App\Models\OdpTask;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\FollowUpSchedule;
use App\Support\Audit;
use App\Support\DateFormats;
use App\Support\Notify;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2, ODP follow-up: an assessor records a follow-up meeting (the 1st, 2nd or 3rd),
 * in order, while that follow-up is open: when it was held, who came, what was said, and
 * for every task where it stands now and an update (decided 12 Nov 2026: tasks are updated
 * together, here, not one by one in between). Every task is then copied as it stands, so
 * the meeting shows where things stood then. Recording it again corrects it (until the
 * follow-up is closed). The General Secretary and the other assessors are told.
 */
final class RecordFollowUp
{
    use EnforcesPolicy;

    /**
     * @param  array<int, string|null>  $updates  what was said about each task, by its id
     * @param  array<int, string>  $statuses  where each task stands now (a TaskProgress value), by its id: set for all together
     */
    public function handle(Assessment $assessment, User $by, int $number, string $heldOn, ?string $attendees, string $summary, array $updates = [], array $statuses = []): OdpFollowUp
    {
        $this->ensure($by, 'recordFollowUp', $assessment);
        $slot = collect(FollowUpSchedule::for($assessment))->firstWhere('number', $number)
            ?? throw new WorkflowRuleBroken('There is no follow-up meeting '.$number.' in this year’s plan.');
        // In order, while it is open: the follow-up under way, once its assessors or an Administrator opened it.
        if ($slot['state'] === FollowUpSchedule::LOCKED) {
            throw new WorkflowRuleBroken('Follow-up '.$number.' is closed and locked. An Administrator can reopen it.');
        }
        if ((FollowUpSchedule::next($assessment)['number'] ?? null) !== $number) {
            throw new WorkflowRuleBroken('Close follow-up '.($number - 1).' first: the follow-ups go in order.');
        }
        if ($slot['state'] !== FollowUpSchedule::OPEN) {
            throw new WorkflowRuleBroken('Open follow-up '.$number.' first.');
        }
        $held = Carbon::parse($heldOn)->startOfDay();
        if ($held->isFuture()) {
            throw new WorkflowRuleBroken('Record a follow-up meeting once it has been held.');
        }
        if (trim($summary) === '') {
            throw new WorkflowRuleBroken('Say what was discussed and agreed at the meeting.');
        }
        $correcting = $slot['meeting'] !== null;

        $meeting = DB::transaction(function () use ($assessment, $by, $number, $held, $attendees, $summary, $updates, $statuses): OdpFollowUp {
            // Where each task stands, as agreed at the meeting: Done counts from now (it was seen there).
            foreach ($assessment->odpTasks()->get() as $task) {
                /** @var OdpTask $task */
                $progress = isset($statuses[$task->id]) ? TaskProgress::tryFrom((string) $statuses[$task->id]) : null;
                if ($progress === null || $task->isDropped() || $task->carried_to_assessment_id !== null) {
                    continue;
                }
                $done = $progress === TaskProgress::Done;
                $task->forceFill([
                    'progress' => $progress,
                    'done' => $done,
                    'confirmed_by' => $done ? ($task->done ? $task->confirmed_by : $by->id) : null,
                    'confirmed_at' => $done ? ($task->done ? $task->confirmed_at : now()) : null,
                    'returned_reason' => null,
                ])->save();
            }

            $meeting = OdpFollowUp::query()->updateOrCreate(
                ['assessment_id' => $assessment->id, 'number' => $number],
                ['held_on' => $held->toDateString(), 'attendees' => $attendees !== null && trim($attendees) !== '' ? trim($attendees) : null, 'summary' => trim($summary), 'recorded_by' => $by->id],
            );

            $meeting->tasks()->delete();
            foreach ($assessment->odpTasks()->get() as $i => $task) {
                /** @var OdpTask $task */
                $update = $updates[$task->id] ?? null;
                $meeting->tasks()->create([
                    'odp_task_id' => $task->id,
                    'ref' => $task->ref,
                    'task' => $task->task,
                    'progress' => $task->progress,
                    'done' => $task->done,
                    'update' => $update !== null && trim($update) !== '' ? trim($update) : null,
                    'sort_order' => $i,
                ]);
            }

            Audit::record($by, 'follow_up.recorded', $meeting, $assessment, payload: ['number' => $number, 'held_on' => $held->toDateString()]);

            return $meeting;
        });

        $movement = $assessment->movement;
        Notify::send(
            collect([$movement->generalSecretary()->first()])->merge(Notify::assessorsOf($assessment)),
            new WorkflowNotice(
                ($correcting ? 'Corrected: ' : '')."Follow-up meeting {$number} on {$movement->name}’s ODP",
                "{$by->name} recorded follow-up meeting {$number}, held on {$held->format(DateFormats::DAY)}: where each ODP task stood, and what was agreed.",
                route('follow-up.show', $assessment, false), 'info',
            ),
            except: $by,
        );

        return $meeting;
    }
}
