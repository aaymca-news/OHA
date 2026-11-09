<?php

namespace App\Actions\Evidence;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Models\Evidence;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Shares a document with every other movement, or stops sharing it. Only the document
 * is shared: other movements see its title, file, category and movement, never the
 * movement's scores or form.
 */
final class ShareEvidence
{
    use EnforcesPolicy;

    public function handle(User $by, Evidence $evidence, bool $shared): Evidence
    {
        $this->ensure($by, 'share', $evidence);

        if ($evidence->isShared() === $shared) {
            return $evidence;
        }

        return DB::transaction(function () use ($by, $evidence, $shared): Evidence {
            $evidence->update(['shared_at' => $shared ? now() : null, 'shared_by' => $shared ? $by->id : null]);
            Audit::record($by, $shared ? 'evidence.shared' : 'evidence.unshared', $evidence, $evidence->assessment, payload: ['title' => $evidence->title]);

            return $evidence;
        });
    }
}
