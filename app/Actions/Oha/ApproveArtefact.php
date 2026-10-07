<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\CommentKind;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Category;
use App\Models\CategoryScore;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\Scorer;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * An Administrator approves a submitted form, report or ODP. This is what lets the
 * assessor go on to the next step, and from this moment the document is visible to
 * every AAYMCA staff member. An approved report or ODP is also visible to the
 * movement's Board Chairperson, who is asked to sign the ODP.
 *
 * An approved OHA form records its score: the points are recomputed from the
 * stored answers and frozen in category_scores with each category's current
 * maximum, so every figure for the movement updates at once. An approved report
 * or ODP records which version was approved.
 */
final class ApproveArtefact
{
    use EnforcesPolicy, LocksArtefact;

    public function __construct(private readonly Scorer $scorer) {}

    public function handle(Artefact $artefact, User $approver, ?string $note = null): Artefact
    {
        return DB::transaction(function () use ($artefact, $approver, $note): Artefact {
            $artefact = $this->lock($artefact);
            $this->ensure($approver, 'approve', $artefact);

            $assessment = $artefact->assessment;
            $scores = $artefact->kind === ArtefactKind::Form ? $this->freezeScores($artefact, $approver) : null;
            $version = $artefact->kind !== ArtefactKind::Form ? $this->approveLatestVersion($artefact, $approver) : null;

            $from = $artefact->state;
            $artefact->update([
                'state' => ArtefactState::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            if ($note !== null && trim($note) !== '') {
                $artefact->comments()->create(['user_id' => $approver->id, 'kind' => CommentKind::ApprovalNote, 'body' => trim($note), 'created_at' => now()]);
            }

            Audit::record($approver, $artefact->kind->value.'.approved', $artefact, $assessment, $from, $artefact->state,
                payload: array_filter(['points' => $scores, 'version' => $version, 'note' => $note]));

            $name = SubmitForApproval::label($artefact).($version !== null ? " (version {$version})" : '');
            $movement = $assessment->movement->name;
            $onwards = match ($artefact->kind) {
                ArtefactKind::Form => 'Its score is now recorded.',
                ArtefactKind::Report => 'All AAYMCA staff and the Board Chairperson can now see it.',
                ArtefactKind::Odp => 'All AAYMCA staff can now see it, and it is with the Board Chairperson for signature.',
            };
            Notify::send([$artefact->submitter], new WorkflowNotice(
                "Approved: {$name}, {$movement}",
                "{$approver->name} approved the ".SubmitForApproval::inSentence($name).". {$onwards}",
                Notify::link($assessment), 'good',
            ));

            if ($artefact->kind !== ArtefactKind::Form) {
                $toSign = $artefact->kind === ArtefactKind::Odp;
                Notify::send([$assessment->movement->chair], new WorkflowNotice(
                    ($toSign ? 'Please sign: ' : 'Now available: ')."{$name}, {$movement}",
                    'The '.SubmitForApproval::inSentence($name)." for {$movement} ({$assessment->period_label}) has been approved by AAYMCA."
                        .($toSign ? ' Please read it and sign it to validate it on behalf of your board.' : ' You can read and download it.'),
                    Notify::link($assessment), $toSign ? 'action' : 'info',
                ));
            }

            return $artefact;
        });
    }

    /**
     * @return array<string, int|null>
     */
    private function freezeScores(Artefact $form, User $approver): array
    {
        $upload = $form->currentUpload()->first()
            ?? throw new WorkflowRuleBroken('There is no uploaded form to approve.');

        // The answers as recorded: read from the file, with any typed in the platform.
        $points = $this->scorer->score($upload->answers, $upload->form_meta['missing_sheets'] ?? []);
        $upload->update(['approved_by' => $approver->id, 'approved_at' => now()]);

        // A corrected form approved again replaces the score recorded before; the audit trail keeps both.
        CategoryScore::query()->where('assessment_id', $form->assessment_id)->delete();

        foreach (Category::query()->weighted()->get() as $category) {
            CategoryScore::query()->create([
                'assessment_id' => $form->assessment_id,
                'category_id' => $category->id,
                'points' => $points[$category->code] ?? 0,
                'max_points_at_scoring' => $category->max_points,
                'form_upload_id' => $upload->id,
                'scored_at' => now(),
            ]);
        }

        return array_filter($points, fn ($p) => $p !== null);
    }

    /** The version that was submitted is the newest one; it is the one approved. Returns its number. */
    private function approveLatestVersion(Artefact $artefact, User $approver): int
    {
        $version = $artefact->latestVersion()->first()
            ?? throw new WorkflowRuleBroken('There is no uploaded version to approve.');

        $version->update(['approved_by' => $approver->id, 'approved_at' => now()]);

        return $version->versionNumber();
    }
}
