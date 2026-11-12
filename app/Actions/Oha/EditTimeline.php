<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\Gate;
use App\Models\OdpTask;
use App\Models\TimelineItem;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Changes an assessment's timeline: a deadline for one of the four gates, a
 * step staff add ("Form sent to the movement", "Field visit"), or one of the ODP's
 * tasks (typed in, or corrected after being read from the ODP).
 *
 * A gate deadline a person sets is authoritative: it replaces both default
 * clocks for that gate. Clearing it returns the gate to its SLA. The gates
 * themselves cannot be removed. Every change is audited, and the movement's
 * assessors are told when someone else makes it.
 */
final class EditTimeline
{
    use EnforcesPolicy;

    public function setGateDeadline(Assessment $assessment, string $gateCode, ?string $dueOn, User $user): void
    {
        $this->ensure($user, 'editTimeline', $assessment);
        $gate = Gate::query()->where('code', $gateCode)->firstOrFail();
        $due = $dueOn !== null ? $this->date($dueOn, $gate->milestone_label) : null;

        DB::transaction(function () use ($assessment, $gate, $due, $user): void {
            $existing = $assessment->timelineItems()->where('gate_id', $gate->id)->first();
            $before = $existing?->due_on?->toDateString();

            if ($due === null) {
                $existing?->delete();
            } elseif ($existing !== null) {
                $existing->update(['due_on' => $due, 'updated_by' => $user->id]);
            } else {
                $assessment->timelineItems()->create(['gate_id' => $gate->id, 'due_on' => $due, 'created_by' => $user->id]);
            }

            if ($before !== $due) {
                $this->recorded($assessment, $user, $gate->milestone_label.': '.($before ?? 'default').' → '.($due ?? 'default'));
            }
        });
    }

    public function addStep(Assessment $assessment, string $label, ?string $dueOn, User $user): TimelineItem
    {
        $this->ensure($user, 'editTimeline', $assessment);
        $label = trim($label);
        if ($label === '') {
            throw new WorkflowRuleBroken('Give the step a name.');
        }
        $due = $dueOn !== null ? $this->date($dueOn, $label) : null;

        return DB::transaction(function () use ($assessment, $label, $due, $user): TimelineItem {
            $step = $assessment->timelineItems()->create(['label' => $label, 'due_on' => $due, 'created_by' => $user->id]);
            $this->recorded($assessment, $user, 'added "'.$label.'" '.($due ?? 'no date'));

            return $step;
        });
    }

    public function updateStep(TimelineItem $step, ?string $dueOn, bool $done, User $user): TimelineItem
    {
        $assessment = $step->assessment;
        $this->ensure($user, 'editTimeline', $assessment);
        if ($step->gate_id !== null) {
            throw new WorkflowRuleBroken('A gate is done when its document is; set its deadline instead.');
        }
        $due = $dueOn !== null ? $this->date($dueOn, (string) $step->label) : null;

        return DB::transaction(function () use ($step, $assessment, $due, $done, $user): TimelineItem {
            $changes = [];
            if ($step->due_on?->toDateString() !== $due) {
                $changes[] = $step->label.' '.($step->due_on?->toDateString() ?? '—').' → '.($due ?? '—');
            }
            if (($step->done_on !== null) !== $done) {
                $changes[] = $step->label.($done ? ' done' : ' reopened');
            }

            $step->update(['due_on' => $due, 'done_on' => $done ? ($step->done_on ?? now()->toDateString()) : null, 'updated_by' => $user->id]);

            if ($changes !== []) {
                $this->recorded($assessment, $user, implode('; ', $changes));
            }

            return $step;
        });
    }

    public function removeStep(TimelineItem $step, User $user): void
    {
        $assessment = $step->assessment;
        $this->ensure($user, 'editTimeline', $assessment);
        if ($step->gate_id !== null) {
            throw new WorkflowRuleBroken('The four gates cannot be removed from the timeline.');
        }

        DB::transaction(function () use ($step, $assessment, $user): void {
            $label = (string) $step->label;
            $step->delete();
            $this->recorded($assessment, $user, 'removed "'.$label.'"');
        });
    }

    /**
     * An ODP task typed in: the ODP could not be read, or left it out.
     *
     * @param  array{task: string, ref?: string|null, responsible?: string|null}  $details
     */
    public function addTask(Assessment $assessment, array $details, User $user): OdpTask
    {
        $this->ensure($user, 'editTimeline', $assessment);
        $task = trim($details['task']);
        if ($task === '') {
            throw new WorkflowRuleBroken('Say what the task is.');
        }

        return DB::transaction(function () use ($assessment, $details, $task, $user): OdpTask {
            $created = $assessment->odpTasks()->create([
                'source' => OdpTask::TYPED,
                'ref' => $this->clean($details['ref'] ?? null),
                'task' => $task,
                'responsible' => $this->clean($details['responsible'] ?? null),
                'sort_order' => 1000 + $assessment->odpTasks()->count(),
                'edited_by' => $user->id,
                'edited_at' => now(),
            ]);
            $this->recorded($assessment, $user, 'ODP task added: "'.Str::limit($task, 60).'"');

            return $created;
        });
    }

    /**
     * Corrects an ODP task: its wording and who is responsible. Tasks have no deadlines of
     * their own, and where one stands is set at the follow-ups (decided 12 Nov 2026).
     *
     * @param  array{task: string, responsible?: string|null}  $details
     */
    public function updateTask(OdpTask $odpTask, array $details, User $user): OdpTask
    {
        $assessment = $odpTask->assessment;
        $this->ensure($user, 'editTimeline', $assessment);
        $task = trim($details['task']);
        if ($task === '') {
            throw new WorkflowRuleBroken('Say what the task is.');
        }

        return DB::transaction(function () use ($odpTask, $assessment, $details, $task, $user): OdpTask {
            $odpTask->fill(['task' => $task, 'responsible' => $this->clean($details['responsible'] ?? null)]);
            if (! $odpTask->isDirty()) {
                return $odpTask;
            }
            $odpTask->fill(['edited_by' => $user->id, 'edited_at' => now()])->save();
            $this->recorded($assessment, $user, 'ODP task "'.Str::limit($task, 60).'": details corrected');

            return $odpTask;
        });
    }

    public function removeTask(OdpTask $odpTask, User $user): void
    {
        $assessment = $odpTask->assessment;
        $this->ensure($user, 'editTimeline', $assessment);

        DB::transaction(function () use ($odpTask, $assessment, $user): void {
            $task = $odpTask->task;
            $odpTask->delete();
            $this->recorded($assessment, $user, 'ODP task removed: "'.Str::limit($task, 60).'"');
        });
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(string $value, string $label): string
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? Carbon::createFromFormat('Y-m-d', $value) : null;

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new WorkflowRuleBroken("Dates must be real dates ({$label}).");
        }

        return $value;
    }

    private function recorded(Assessment $assessment, User $user, string $change): void
    {
        Audit::record($user, 'timeline.updated', $assessment, $assessment, payload: ['change' => $change]);

        $movement = $assessment->movement->name;
        Notify::send(Notify::assessorsOf($assessment), new WorkflowNotice(
            "Timeline changed: {$movement}",
            "{$user->name} changed the timeline for {$movement} ({$assessment->period_label}): {$change}.",
            Notify::link($assessment, 'timeline'), 'info',
        ), except: $user);
    }
}
