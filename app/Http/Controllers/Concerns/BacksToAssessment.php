<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Artefact;
use App\Models\Assessment;
use Illuminate\Http\RedirectResponse;

/**
 * Back to an assessment's tab after an action, with what happened. An artefact goes back
 * to its own tab (form, report, ODP) unless another tab is named.
 */
trait BacksToAssessment
{
    protected function toTab(Assessment|Artefact|int $of, string $status, ?string $tab = null, ?string $fragment = null): RedirectResponse
    {
        $tab ??= $of instanceof Artefact ? $of->kind->value : 'form';
        $assessment = match (true) {
            $of instanceof Artefact => $of->assessment_id,
            $of instanceof Assessment => $of->id,
            default => $of,
        };

        return redirect()->to(Assessment::tabUrl($assessment, $tab).($fragment !== null ? '#'.$fragment : ''))->with('status', $status);
    }
}
