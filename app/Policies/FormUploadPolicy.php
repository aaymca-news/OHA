<?php

namespace App\Policies;

use App\Models\FormUpload;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class FormUploadPolicy
{
    /**
     * Whoever may see the OHA form may download it: Secretariat users only, since
     * boards see the report and ODP, not the raw form. The rule lives in ArtefactPolicy::view.
     */
    public function download(User $user, FormUpload $formUpload): Response
    {
        return Gate::forUser($user)->inspect('view', $formUpload->artefact);
    }
}
