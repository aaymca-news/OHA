<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\FormFinding;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Closes off a gap in the form with a note, e.g. the NGS confirms a figure by
 * email after the form came in. The note is the evidence; the file is never
 * changed. If the answer would change the score, upload a corrected form instead.
 */
final class ResolveGap
{
    use EnforcesPolicy;

    public function resolve(FormFinding $gap, User $user, string $note): FormFinding
    {
        $this->ensure($user, 'resolve', $gap);

        if (mb_strlen(trim($note)) < 3) {
            throw new WorkflowRuleBroken('Say how it was resolved: the validator reads this in place of the missing answer.');
        }
        if ($gap->resolved_at !== null) {
            throw new WorkflowRuleBroken('That item is already resolved.');
        }

        return DB::transaction(function () use ($gap, $user, $note): FormFinding {
            $gap->update(['resolved_by' => $user->id, 'resolved_at' => now(), 'resolved_note' => trim($note)]);

            Audit::record($user, 'gap.resolved', $gap, $gap->formUpload->artefact->assessment,
                payload: ['ref' => $gap->ref, 'note' => trim($note)]);

            return $gap;
        });
    }

    public function reopen(FormFinding $gap, User $user): FormFinding
    {
        $this->ensure($user, 'resolve', $gap);

        if ($gap->resolved_at === null) {
            throw new WorkflowRuleBroken('That item is not resolved.');
        }

        return DB::transaction(function () use ($gap, $user): FormFinding {
            $previousNote = $gap->resolved_note;
            $gap->update(['resolved_by' => null, 'resolved_at' => null, 'resolved_note' => null]);

            Audit::record($user, 'gap.reopened', $gap, $gap->formUpload->artefact->assessment,
                payload: ['ref' => $gap->ref, 'previous_note' => $previousNote]);

            return $gap;
        });
    }
}
