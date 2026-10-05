<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Enums\ArtefactState;
use App\Enums\CommentKind;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * An Administrator returns a submitted form, report or ODP for
 * changes. A reason is required: it is what the assessor works from.
 */
final class SendBack
{
    use EnforcesPolicy, LocksArtefact;

    public function handle(Artefact $artefact, User $approver, string $reason): Artefact
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new WorkflowRuleBroken('Say why it is being sent back: the assessor works from your reason.');
        }

        return DB::transaction(function () use ($artefact, $approver, $reason): Artefact {
            $artefact = $this->lock($artefact);
            $this->ensure($approver, 'approve', $artefact);

            $assessment = $artefact->assessment;
            $from = $artefact->state;

            $artefact->update(['state' => ArtefactState::Rejected, 'returned_at' => now()]);
            $artefact->comments()->create(['user_id' => $approver->id, 'kind' => CommentKind::SendBack, 'body' => trim($reason), 'created_at' => now()]);

            Audit::record($approver, $artefact->kind->value.'.sent_back', $artefact, $assessment, $from, $artefact->state,
                payload: ['reason' => trim($reason)]);

            $name = SubmitForApproval::label($artefact);
            Notify::send([$artefact->submitter], new WorkflowNotice(
                "Sent back: {$name}, {$assessment->movement->name}",
                "{$approver->name} returned the ".lcfirst($name).' for changes: '.trim($reason),
                Notify::link($assessment), 'serious',
            ));

            return $artefact;
        });
    }
}
