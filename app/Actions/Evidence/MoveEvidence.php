<?php

namespace App\Actions\Evidence;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Models\Category;
use App\Models\Evidence;
use App\Models\User;
use App\Oha\EvidenceQuestions;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Moves evidence filed in the wrong folder to another: an OHA category, or General
 * (null). A category folder holds only its own questions and ODP tasks, so links that do
 * not fit the new folder are taken off; General keeps them all. The audit trail records
 * the move and anything taken off.
 */
final class MoveEvidence
{
    use EnforcesPolicy;

    /**
     * @return list<string> the links taken off, as shown ("Q811 Website", "ODP 7.2")
     */
    public function handle(User $by, Evidence $evidence, ?Category $to): array
    {
        $this->ensure($by, 'move', $evidence);

        if ($evidence->category_id === $to?->id) {
            return [];
        }

        return DB::transaction(function () use ($by, $evidence, $to): array {
            $from = $evidence->category;
            $questions = DB::table('evidence_questions')->where('evidence_id', $evidence->id)->pluck('question_code');
            $tasks = DB::table('evidence_odp_tasks')->where('evidence_id', $evidence->id)->pluck('task');

            $offQuestions = $to === null ? [] : $questions->filter(fn (string $code) => EvidenceQuestions::categoryOf($code) !== $to->code)->values()->all();
            $offTasks = $to === null ? [] : $tasks->filter(fn (string $task) => (int) strtok($task, '.') !== $to->form_order)->values()->all();

            DB::table('evidence_questions')->where('evidence_id', $evidence->id)->whereIn('question_code', $offQuestions)->delete();
            DB::table('evidence_odp_tasks')->where('evidence_id', $evidence->id)->whereIn('task', $offTasks)->delete();
            $evidence->update(['category_id' => $to?->id]);

            $takenOff = [
                ...array_map(fn (string $code) => EvidenceQuestions::shown($code).' '.EvidenceQuestions::label($code), $offQuestions),
                ...array_map(fn (string $task) => "ODP {$task}", $offTasks),
            ];

            Audit::record($by, 'evidence.moved', $evidence, $evidence->assessment, from: $from->code ?? 'general', to: $to->code ?? 'general',
                payload: array_filter(['title' => $evidence->title, 'links_taken_off' => $takenOff]));

            return $takenOff;
        });
    }
}
