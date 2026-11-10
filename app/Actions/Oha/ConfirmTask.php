<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Enums\TaskProgress;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\OdpTask;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stage 2, ODP follow-up: an assessor confirms an ODP task reported done (it then
 * counts as done), or sends it back as still in progress, saying why. Whoever reported
 * it is told.
 */
final class ConfirmTask
{
    use EnforcesPolicy;

    public function handle(OdpTask $task, User $by, bool $confirmed, ?string $reason = null): OdpTask
    {
        $this->ensure($by, 'confirm', $task);
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        if (! $confirmed && $reason === null) {
            throw new WorkflowRuleBroken('Say why the task is not done yet, so the movement knows what is missing.');
        }
        $assessment = $task->assessment;

        DB::transaction(function () use ($task, $by, $confirmed, $reason, $assessment): void {
            $task->newQuery()->whereKey($task->id)->lockForUpdate()->first();
            $task->fill($confirmed
                ? ['done' => true, 'confirmed_by' => $by->id, 'confirmed_at' => now(), 'returned_reason' => null]
                : ['progress' => TaskProgress::InProgress, 'done' => false, 'confirmed_by' => null, 'confirmed_at' => null, 'returned_reason' => $reason])->save();

            Audit::record($by, $confirmed ? 'odp_task.confirmed' : 'odp_task.returned', $task, $assessment,
                payload: array_filter(['task' => Str::limit($task->task, 100), 'reason' => $reason]));
        });

        $reporter = $task->reporter;
        if ($reporter !== null) {
            Notify::send([$reporter], new WorkflowNotice(
                $confirmed ? 'Confirmed done: an ODP task' : 'Not confirmed yet: an ODP task',
                $confirmed
                    ? "{$by->name} confirmed this task done: “".Str::limit($task->task, 120).'”.'
                    : "{$by->name} did not confirm this task done yet: “".Str::limit($task->task, 120)."”. Why: {$reason}",
                route('follow-up.show', $assessment, false), $confirmed ? 'good' : 'serious',
            ), except: $by);
        }

        return $task->refresh();
    }
}
