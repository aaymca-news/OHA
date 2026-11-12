<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
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
 * Stage 2, between the follow-ups (decided 12 Nov 2026): the movement's National General
 * Secretary or an assessor adds a note on an ODP task (what was done, what is in the way)
 * and may link evidence already in the library. Where the task stands is not changed here:
 * it is set at the next follow-up meeting, for every task together. When the General
 * Secretary adds one, the assessors are told.
 */
final class NoteOnTask
{
    use EnforcesPolicy;

    public function handle(OdpTask $task, User $by, ?string $note, ?int $evidenceId = null): OdpTask
    {
        $this->ensure($by, 'note', $task);
        $assessment = $task->assessment;
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        if ($evidenceId !== null && ! Evidence::query()->whereKey($evidenceId)->where('assessment_id', $assessment->id)->exists()) {
            throw new WorkflowRuleBroken('Choose evidence from this assessment’s library.');
        }
        if ($note === null && $evidenceId === null) {
            throw new WorkflowRuleBroken('Write a note, or choose the evidence to link.');
        }

        DB::transaction(function () use ($task, $by, $note, $evidenceId, $assessment): void {
            $task->newQuery()->whereKey($task->id)->lockForUpdate()->first();
            $task->fill(array_filter([
                'progress_note' => $note,
                'evidence_id' => $evidenceId,
            ], fn ($v) => $v !== null) + ['reported_by' => $by->id, 'reported_at' => now()])->save();

            Audit::record($by, 'odp_task.noted', $task, $assessment, payload: array_filter([
                'task' => Str::limit($task->task, 100),
                'note' => $note,
                'evidence' => $evidenceId,
            ]));
        });

        if (! $by->canAssess($assessment->movement)) {
            Notify::send(Notify::assessorsOf($assessment), new WorkflowNotice(
                "A note on an ODP task of {$assessment->movement->name}",
                "{$by->name} added ".($note !== null ? 'a note' : 'evidence').' on this task: “'.Str::limit($task->task, 120).'”'
                    .($note !== null ? ': “'.Str::limit($note, 200).'”' : '').'. Where it stands is set at the next follow-up.',
                route('follow-up.show', $assessment, false), 'info',
            ), except: $by);
        }

        return $task->refresh();
    }
}
