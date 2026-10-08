<?php

namespace App\Support;

use App\Actions\Oha\SubmitForApproval;
use App\Models\Artefact;
use App\Models\User;
use App\Notifications\WorkflowNotice;

/**
 * An approved OHA form, report or ODP was changed (a new upload or version, a change
 * in Google Drive, or an answer typed in the platform). The Administrators are told at
 * once that it will need their approval again; until they give it, everyone keeps
 * seeing the approved one.
 */
final class ChangedAfterApproval
{
    /**
     * @param  User  $by  who made the change; they are not told about their own change
     * @param  string  $happened  what happened, up to the document: "Tendai Moyo uploaded a new file for"
     */
    public static function tell(Artefact $artefact, User $by, string $happened): void
    {
        $assessment = $artefact->assessment;
        $name = SubmitForApproval::label($artefact);
        $movement = $assessment->movement->name;

        Notify::send(Approvers::for($by), new WorkflowNotice(
            "Changed after approval: {$name}, {$movement}",
            "{$happened} the approved ".SubmitForApproval::inSentence($name)." for {$movement} ({$assessment->period_label}). "
                .'It needs your approval again once it is submitted. Until then, everyone keeps seeing the approved '.SubmitForApproval::inSentence($name).'.',
            Notify::link($assessment), 'action',
        ));
    }

    /**
     * Changed while it waits for the Administrators' decision: it stays with them, and what
     * they approve is now the changed one.
     */
    public static function whileWaiting(Artefact $artefact, User $by, string $happened): void
    {
        $assessment = $artefact->assessment;
        $name = SubmitForApproval::label($artefact);
        $movement = $assessment->movement->name;

        Notify::send(Approvers::for($by), new WorkflowNotice(
            "Changed while waiting for approval: {$name}, {$movement}",
            "{$happened} the ".SubmitForApproval::inSentence($name)." for {$movement} ({$assessment->period_label}) while it waits for your approval. "
                .'It is still with you; look again before deciding: what you approve is the changed one.',
            Notify::link($assessment), 'action',
        ));
    }
}
