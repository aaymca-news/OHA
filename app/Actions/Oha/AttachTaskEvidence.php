<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Actions\Evidence\UploadEvidence;
use App\Models\Evidence;
use App\Models\OdpTask;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Str;

/**
 * Stage 2, ODP follow-up: evidence added right on an ODP task (a file or a link). It goes
 * into the movement's evidence library, as if added in Resources, backing that task (by
 * its ODP number), so it shows there at once; and it becomes the task's evidence.
 */
final class AttachTaskEvidence
{
    use EnforcesPolicy;

    public function __construct(private UploadEvidence $upload) {}

    /** Give either a file ($sourcePath and $originalName) or a $url. */
    public function handle(OdpTask $task, User $by, ?string $sourcePath, ?string $originalName, ?string $url, ?string $title = null): Evidence
    {
        $this->ensure($by, 'report', $task);
        $ref = trim((string) $task->ref);
        $evidence = $this->upload->handle(
            $by, $task->assessment, $sourcePath, $originalName,
            $title ?? Str::limit($task->task, 80, ''),
            tasks: preg_match('/^[1-9]\.[1-9][0-9]?$/', $ref) === 1 ? [$ref] : [],
            url: $url,
        );

        $task->forceFill(['evidence_id' => $evidence->id])->save();
        Audit::record($by, 'odp_task.evidence_added', $task, $task->assessment,
            payload: ['task' => Str::limit($task->task, 100), 'evidence' => $evidence->title]);

        return $evidence;
    }
}
