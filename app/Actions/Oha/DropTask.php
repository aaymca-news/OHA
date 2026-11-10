<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\OdpTask;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stage 2, closing the cycle: an assessor drops an unfinished ODP task instead of
 * carrying it into the next assessment (no longer relevant, or replaced), saying why.
 * It then counts as settled. Undone by reopening it (drop again with $undo).
 */
final class DropTask
{
    use EnforcesPolicy;

    public function handle(OdpTask $task, User $by, ?string $reason, bool $undo = false): OdpTask
    {
        $this->ensure($by, 'drop', $task);
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        if (! $undo && $reason === null) {
            throw new WorkflowRuleBroken('Say why the task is dropped, so the record shows it.');
        }

        DB::transaction(function () use ($task, $by, $reason, $undo): void {
            $task->newQuery()->whereKey($task->id)->lockForUpdate()->first();
            $task->fill($undo
                ? ['dropped_at' => null, 'dropped_by' => null, 'dropped_reason' => null]
                : ['dropped_at' => now(), 'dropped_by' => $by->id, 'dropped_reason' => $reason])->save();

            Audit::record($by, $undo ? 'odp_task.restored' : 'odp_task.dropped', $task, $task->assessment,
                payload: array_filter(['task' => Str::limit($task->task, 100), 'reason' => $reason]));
        });

        return $task->refresh();
    }
}
