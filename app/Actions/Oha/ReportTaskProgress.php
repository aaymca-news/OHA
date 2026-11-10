<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Enums\TaskProgress;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Evidence;
use App\Models\OdpTask;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stage 2, ODP follow-up: the movement's National General Secretary or an assessor says
 * where an ODP task stands, with a note and, for Done, the evidence. A Done from the
 * General Secretary waits for an assessor to confirm it (the assessors are told); a
 * Done from an assessor counts at once, as the assessors are the ones who confirm.
 */
final class ReportTaskProgress
{
    use EnforcesPolicy;

    public function handle(OdpTask $task, User $by, TaskProgress $progress, ?string $note = null, ?int $evidenceId = null): OdpTask
    {
        $this->ensure($by, 'report', $task);
        $assessment = $task->assessment;
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        if ($evidenceId !== null && ! Evidence::query()->whereKey($evidenceId)->where('assessment_id', $assessment->id)->exists()) {
            throw new WorkflowRuleBroken('Choose evidence from this assessment’s library.');
        }
        $confirmsItself = $by->canAssess($assessment->movement);
        $done = $progress === TaskProgress::Done;

        DB::transaction(function () use ($task, $by, $progress, $note, $evidenceId, $confirmsItself, $done, $assessment): void {
            $task->newQuery()->whereKey($task->id)->lockForUpdate()->first();
            $task->fill([
                'progress' => $progress,
                'progress_note' => $note,
                'evidence_id' => $evidenceId,
                'reported_by' => $by->id,
                'reported_at' => now(),
                'returned_reason' => null,
                'done' => $done && $confirmsItself,
                'confirmed_by' => $done && $confirmsItself ? $by->id : null,
                'confirmed_at' => $done && $confirmsItself ? now() : null,
            ])->save();

            Audit::record($by, 'odp_task.progress', $task, $assessment, to: $progress, payload: array_filter([
                'task' => Str::limit($task->task, 100),
                'note' => $note,
                'confirmed' => $done && $confirmsItself ? true : null,
            ]));
        });

        if ($done && ! $confirmsItself) {
            Notify::send(Notify::assessorsOf($assessment), new WorkflowNotice(
                "Please confirm: an ODP task of {$assessment->movement->name} is reported done",
                "{$by->name} reports this task done: “".Str::limit($task->task, 120).'”. Check it and confirm it, or send it back with a reason.',
                route('follow-up.show', $assessment, false), 'action',
            ), except: $by);
        }

        return $task->refresh();
    }
}
