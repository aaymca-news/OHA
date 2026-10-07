<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Enums\ArtefactState;
use App\Enums\FindingSeverity;
use App\Models\FormUpload;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes an OHA form uploaded by mistake: the wrong file, or the wrong movement's.
 * The form then stands as the upload before it left it (approved, ready or refused),
 * or as not started if there is none. The approved upload is never deleted: it is the
 * record of the score. The deletion itself stays in the audit trail.
 */
final class DeleteFormUpload
{
    use EnforcesPolicy, LocksArtefact;

    public function handle(FormUpload $upload, User $user): void
    {
        $this->ensure($user, 'delete', $upload);

        DB::transaction(function () use ($upload, $user): void {
            $form = $this->lock($upload->artefact);
            $this->ensure($user, 'delete', $upload);

            $assessment = $form->assessment;
            $from = $form->state;
            [$disk, $path, $name, $sha] = [$upload->disk, $upload->path, $upload->original_name, $upload->sha256];
            $wasCurrent = $form->currentUpload()->value('id') === $upload->id;

            $upload->delete();

            if ($wasCurrent) {
                $previous = $form->uploads()->latest('id')->with('findings')->first();
                $form->update(match (true) {
                    $previous === null => ['state' => ArtefactState::NotStarted, 'approved_by' => null, 'approved_at' => null],
                    $previous->isApproved() => ['state' => ArtefactState::Approved, 'approved_by' => $previous->approved_by, 'approved_at' => $previous->approved_at],
                    $previous->findings->contains('severity', FindingSeverity::Error) => ['state' => ArtefactState::RulesFailed],
                    default => ['state' => ArtefactState::Ready],
                } + ['gap_ack' => false, 'gap_ack_reason' => null]);
            }

            // The same bytes may have been uploaded again; the file goes only when nothing else uses it.
            if (! FormUpload::query()->where('disk', $disk)->where('path', $path)->exists()) {
                DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
            }

            Audit::record($user, 'form.upload_deleted', $form, $assessment, $from, $form->state, payload: ['file' => $name, 'sha256' => $sha]);
        });
    }
}
