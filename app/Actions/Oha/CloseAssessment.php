<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Models\Assessment;
use App\Models\FollowUpWindow;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\DateFormats;
use App\Support\Notify;
use App\Support\VersionPruner;
use Illuminate\Support\Facades\DB;

/**
 * Stage 1 done (12 Nov 2026, in place of the Board Chairperson's signature): once the ODP
 * is approved, an assessor on the movement, an Administrator or the Super Administrator
 * closes the assessment. From then on:
 *
 *   - the OHA form, the report and the ODP are frozen as approved: any work on the form or
 *     report since their approval is removed (as signing used to do);
 *   - its approved scores are the movement's Baseline 1;
 *   - the follow-up begins: its three follow-ups have no fixed dates; the first one's date,
 *     agreed with the movement, can be given now (or later, on the follow-up page).
 *
 * The Board Chairperson may still sign the ODP, before or after, which only marks it
 * "Validated by the board". The movement's National General Secretary and its assessors
 * are told; the Board Chairperson is not reminded of anything.
 */
final class CloseAssessment
{
    use EnforcesPolicy;

    public function __construct(
        private readonly SupplyAnswer $supply,
        private readonly RecordBaseline $baselines,
    ) {}

    public function handle(Assessment $assessment, User $by, ?string $firstFollowUpOn = null): Assessment
    {
        $first = FollowUpWindows::date($firstFollowUpOn);
        DB::transaction(function () use ($assessment, $by, $first): void {
            $assessment->newQuery()->whereKey($assessment->id)->lockForUpdate()->first();
            $assessment->refresh();
            $this->ensure($by, 'close', $assessment);

            // The OHA form and report the ODP rests on are final as approved: work on them since goes.
            $form = $assessment->form()->firstOrFail();
            $removed = VersionPruner::finalise($form, $assessment->report()->firstOrFail());
            $approvedUpload = $form->uploads()->whereNotNull('approved_at')->orderByDesc('id')->first();
            $restored = $approvedUpload !== null && $this->supply->restoreApproved($approvedUpload);

            $assessment->forceFill(['closed_at' => now(), 'closed_by' => $by->id])->save();
            if ($first !== null) {
                FollowUpWindow::query()->create(['assessment_id' => $assessment->id, 'number' => 1])
                    ->forceFill(['due_on' => $first->toDateString(), 'due_set_by' => $by->id])->save();
            }

            Audit::record($by, 'assessment.closed', $assessment, $assessment, payload: array_filter([
                'odp_version' => $assessment->odp()->first()?->approvedVersion()->first()?->versionNumber(),
                'first_follow_up_on' => $first?->toDateString(),
                'unapproved_work_removed' => $removed,
                'typed_answers_since_approval_dropped' => $restored,
            ]));
            // Its approved scores, and the answers they came from, are Baseline 1 (recorded after the closing, as it follows from it).
            $this->baselines->first($assessment, $by);
        });

        $assessment->refresh();
        $movement = $assessment->movement;
        $when = $first !== null ? ' The first follow-up is planned for '.$first->format(DateFormats::DAY).'.' : '';
        Notify::send(Notify::assessorsOf($assessment), new WorkflowNotice(
            "Assessment closed: {$movement->name}",
            "{$by->name} closed the {$movement->name} assessment ({$assessment->period_label}). Its scores are Baseline 1, and the OHA form, report and ODP are frozen as approved.{$when}",
            route('follow-up.show', $assessment, false), 'good',
        ), except: $by);
        Notify::send([$movement->generalSecretary], new WorkflowNotice(
            "Follow-up begins: {$movement->name}",
            "AAYMCA has closed your {$assessment->period_label} assessment. Its scores are your starting point (Baseline 1), and the follow-up of your ODP begins.{$when} You can report progress on the ODP tasks and add evidence.",
            route('follow-up.show', $assessment, false), 'info',
        ));

        return $assessment;
    }
}
