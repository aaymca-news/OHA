<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
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
 * Stage 2, ODP follow-up: an assessor records a follow-up meeting on the signed ODP
 * (the 1st, 2nd or 3rd of the year): when it was held, who came, what was said, and
 * an update on each task. Every task is copied as it stands now, so the meeting shows
 * where things stood then. Recording it again corrects it. The movement is told.
 */
final class RecordFollowUp
{
    use EnforcesPolicy;

    /**
     * @param  array<int, string|null>  $updates  what was said about each task, by its id
     */
    public function handle(Assessment $assessment, User $by, int $number, string $heldOn, ?string $attendees, string $summary, array $updates = []): OdpFollowUp
    {
        $this->ensure($by, 'recordFollowUp', $assessment);
        $slot = collect(FollowUpSchedule::for($assessment))->firstWhere('number', $number)
            ?? throw new WorkflowRuleBroken('There is no follow-up meeting '.$number.' in this year’s plan.');
        $held = Carbon::parse($heldOn)->startOfDay();
        if ($held->isFuture()) {
            throw new WorkflowRuleBroken('Record a follow-up meeting once it has been held.');
        }
        if (trim($summary) === '') {
            throw new WorkflowRuleBroken('Say what was discussed and agreed at the meeting.');
        }
        $correcting = $slot['meeting'] !== null;

        $meeting = DB::transaction(function () use ($assessment, $by, $number, $held, $attendees, $summary, $updates): OdpFollowUp {
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
            collect([$movement->chair()->first(), $movement->generalSecretary()->first()])->merge(Notify::assessorsOf($assessment)),
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
