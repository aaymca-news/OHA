<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\OdpReview;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2, step 5: an assessor writes the overall review of a signed ODP, before the next
 * assessment: what was achieved, what was not and why, and the lessons. The task counts
 * are kept as they stand now. Writing it again corrects it. The movement is told.
 */
final class RecordOdpReview
{
    use EnforcesPolicy;

    public function handle(Assessment $assessment, User $by, string $achieved, string $notAchieved, ?string $lessons = null): OdpReview
    {
        $this->ensure($by, 'reviewOdp', $assessment);
        if (trim($achieved) === '' || trim($notAchieved) === '') {
            throw new WorkflowRuleBroken('Say what was achieved, and what was not and why.');
        }
        $tasks = $assessment->odpTasks()->get();
        $correcting = $assessment->odpReview()->exists();

        $review = DB::transaction(function () use ($assessment, $by, $achieved, $notAchieved, $lessons, $tasks): OdpReview {
            $review = OdpReview::query()->updateOrCreate(['assessment_id' => $assessment->id], [
                'achieved' => trim($achieved),
                'not_achieved' => trim($notAchieved),
                'lessons' => $lessons !== null && trim($lessons) !== '' ? trim($lessons) : null,
                'tasks_done' => $tasks->where('done', true)->count(),
                'tasks_total' => $tasks->count(),
                'recorded_by' => $by->id,
            ]);
            Audit::record($by, 'odp_review.recorded', $review, $assessment, payload: ['done' => $review->tasks_done, 'total' => $review->tasks_total]);

            return $review;
        });

        $movement = $assessment->movement;
        Notify::send(
            collect([$movement->chair()->first(), $movement->generalSecretary()->first()])->merge(Notify::assessorsOf($assessment)),
            new WorkflowNotice(
                ($correcting ? 'Corrected: ' : '')."The review of {$movement->name}’s {$assessment->period_label} ODP",
                "{$by->name} wrote the review of the ODP: {$review->tasks_done} of {$review->tasks_total} tasks done, what was achieved, and what was not and why.",
                route('follow-up.show', $assessment, false), 'info',
            ),
            except: $by,
        );

        return $review;
    }
}
