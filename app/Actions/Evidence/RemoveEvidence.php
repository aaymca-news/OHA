<?php

namespace App\Actions\Evidence;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Models\Document;
use App\Models\Evidence;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes a document out of the evidence library. The record goes; the audit trail keeps
 * what it was and who removed it. The file goes too, unless something else holds it.
 */
final class RemoveEvidence
{
    use EnforcesPolicy;

    public function handle(User $by, Evidence $evidence): void
    {
        $this->ensure($by, 'remove', $evidence);

        DB::transaction(function () use ($by, $evidence): void {
            Audit::record($by, 'evidence.removed', $evidence, $evidence->assessment, payload: array_filter([
                'title' => $evidence->title, 'file' => $evidence->original_name, 'link' => $evidence->url,
            ]));
            $evidence->delete();
        });

        if ($evidence->isLink()) {
            return;
        }
        $stillHeld = Evidence::query()->where('disk', $evidence->disk)->where('path', $evidence->path)->exists()
            || Document::query()->where('disk', $evidence->disk)->where('path', $evidence->path)->exists();
        if (! $stillHeld) {
            Storage::disk($evidence->disk)->delete($evidence->path);
        }
    }
}
