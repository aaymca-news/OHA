<?php

namespace App\Policies;

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
}
