<?php

namespace App\Policies;

use App\Enums\ArtefactState;
use App\Enums\DocumentPurpose;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class DocumentPolicy
{
    /**
     * Whoever may see the report or ODP may download its files (the rule lives once,
     * in ArtefactPolicy::view). A report version nobody has approved yet is work in
     * progress: only the movement's assessors and the Administrators see it.
     */
    public function download(User $user, Document $document): Response
    {
        $view = Gate::forUser($user)->inspect('view', $document->artefact);
        if ($view->denied()) {
            return $view;
        }

        if ($document->purpose === DocumentPurpose::Uploaded && ! $document->isApproved()
            && ! $user->oversees() && ! $user->canAssess($document->artefact->assessment->movement)) {
            return Response::deny('This version has not been approved yet.');
        }

        return Response::allow();
    }

    /**
     * Withdrawing a version of the report or ODP saved by mistake, perhaps over a
     * colleague's: the newest one, not yet approved, not while the Administrators decide
     * and never once the ODP is signed. By whoever saved it, or an Administrator. The
     * version it replaced is current again.
     */
    public function withdraw(User $user, Document $document): Response
    {
        $artefact = $document->artefact;
        $movement = $artefact->assessment->movement;

        if (! $user->active || ! ($user->oversees() || $user->canAssess($movement))) {
            return Response::deny("Only the assessors on {$movement->name} and the Administrators withdraw a version.");
        }
        if ($document->purpose !== DocumentPurpose::Uploaded || $document->isApproved()) {
            return Response::deny('An approved version is kept. Save a newer version instead.');
        }
        if ($artefact->documents()->where('purpose', DocumentPurpose::Uploaded)->where('id', '>', $document->id)->exists()) {
            return Response::deny('Only the newest version can be withdrawn.');
        }
        if ($artefact->state === ArtefactState::PendingApproval) {
            return Response::deny('It is with the Administrators. Ask them to send it back first.');
        }
        if ($artefact->assessment->isFrozen()) {
            return Response::deny('The Board Chairperson has signed the ODP, so the OHA form, the report and the ODP are frozen.');
        }
        if (! $user->oversees() && $document->created_by !== $user->id) {
            return Response::deny('Only '.($document->creator->name ?? 'the person who saved it').', who saved it, or an Administrator can withdraw it. Save a corrected version instead.');
        }

        return Response::allow();
    }
}
