<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\OdpTask;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stage 2, step 5: an assessor carries unfinished tasks of a signed ODP into the
 * movement's next assessment, so they are followed up there instead of being lost. Each
 * copy is marked where it came from (and keeps no date: the assessor sets a new one);
 * each original is marked where it went. A newer ODP read from Excel leaves them be.
 */
final class CarryOverTasks
{
    use EnforcesPolicy;

    /**
     * @param  list<int>  $taskIds
     * @return int how many were carried
     */
    public function handle(Assessment $from, User $by, array $taskIds): int
    {
        $this->ensure($by, 'carryOdpTasks', $from);
        $to = $from->nextAssessment()
            ?? throw new WorkflowRuleBroken('Unfinished tasks can be carried over once the movement’s next assessment is opened.');
        $tasks = $from->odpTasks()->whereIn('id', $taskIds)->where('done', false)->whereNull('carried_to_assessment_id')->get();
        if ($tasks->isEmpty()) {
            throw new WorkflowRuleBroken('Choose the unfinished tasks to carry over.');
        }

        DB::transaction(function () use ($from, $to, $by, $tasks): void {
            $order = (int) $to->odpTasks()->max('sort_order');
            foreach ($tasks->values() as $i => $task) {
                /** @var OdpTask $task */
                OdpTask::query()->create([
                    'assessment_id' => $to->id,
                    'source' => OdpTask::CARRIED,
                    'ref' => $task->ref,
                    'priority' => $task->priority,
                    'task' => $task->task,
                    'deadline_text' => 'Carried over from the '.$from->period_label.' ODP',
                    'responsible' => $task->responsible,
                    'sort_order' => $order + $i + 1,
                    'carried_from_task_id' => $task->id,
                ]);
                $task->update(['carried_to_assessment_id' => $to->id]);
            }
            Audit::record($by, 'odp_task.carried', $to, $to, payload: [
                'from' => $from->period_label,
                'tasks' => $tasks->map(fn (OdpTask $t) => Str::limit(($t->ref ? $t->ref.' ' : '').$t->task, 80))->all(),
            ]);
        });

        return $tasks->count();
    }
}
