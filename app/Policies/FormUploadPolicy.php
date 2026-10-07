<?php

namespace App\Policies;

use App\Enums\ArtefactState;
use App\Models\FormUpload;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class FormUploadPolicy
{
    /**
     * Whoever may see the OHA form may download and preview it. The rule lives in
     * ArtefactPolicy::view. Before approval that is the movement's assessors and the
     * Administrators; an upload that is not the approved one stays with them.
     */
    public function download(User $user, FormUpload $formUpload): Response
    {
        $form = $formUpload->artefact;
        if (! $formUpload->isApproved() && ! $user->oversees() && ! $user->canAssess($form->assessment->movement)) {
            return Response::deny('Only the assessors and the Administrators see a form that has not been approved.');
        }

        return Gate::forUser($user)->inspect('view', $form);
    }

    /**
     * Typing answers for what the current upload lacks: the movement's assessors and the
     * Administrators, while it is not with the Administrators and until the ODP is signed.
     */
    public function supply(User $user, FormUpload $formUpload): Response
    {
        $form = $formUpload->artefact;

        if ($denied = $this->closed($user, $formUpload)) {
            return $denied;
        }
        if ($form->currentUpload()->value('id') !== $formUpload->id) {
            return Response::deny('Answers are typed on the latest upload only.');
        }
        if ($form->state === ArtefactState::RulesFailed) {
            return Response::deny('This upload was refused. Upload the right form first.');
        }

        return Response::allow();
    }

    /**
     * Deleting an upload made by mistake (the wrong file, the wrong movement). The approved
     * upload is the record and is never deleted; nor is the only form a report rests on.
     */
    public function delete(User $user, FormUpload $formUpload): Response
    {
        if ($denied = $this->closed($user, $formUpload)) {
            return $denied;
        }
        if ($formUpload->isApproved()) {
            return Response::deny('This upload was approved: it is the record of the score, so it is kept. Upload a corrected form instead.');
        }

        $form = $formUpload->artefact;
        $others = $form->uploads()->whereKeyNot($formUpload->id)->exists();
        $reportStarted = $form->assessment->report()->first()?->versions()->exists();
        if (! $others && $reportStarted) {
            return Response::deny('The report rests on this form. Upload a corrected form instead of deleting it.');
        }

        return Response::allow();
    }

    /** Why the form cannot be changed now, or null if it can. */
    private function closed(User $user, FormUpload $formUpload): ?Response
    {
        $form = $formUpload->artefact;
        $movement = $form->assessment->movement;

        if (! $user->active || ! ($user->oversees() || $user->canAssess($movement))) {
            return Response::deny("Only the assessors on {$movement->name} and the Administrators change the OHA form.");
        }
        if ($form->assessment->isFrozen()) {
            return Response::deny('The Board Chairperson has signed the ODP, so the OHA form, the report and the ODP are frozen.');
        }
        if ($form->state === ArtefactState::PendingApproval) {
            return Response::deny('The form is with the Administrators for approval. It can be changed once they decide.');
        }

        return null;
    }
}
