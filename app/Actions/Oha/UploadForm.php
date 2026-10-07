<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Actions\Oha\Concerns\RecordsFormChecks;
use App\Enums\FindingSeverity;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\FormUpload;
use App\Models\Movement;
use App\Models\User;
use App\Oha\CheckResult;
use App\Oha\Finding;
use App\Oha\FindingRule;
use App\Oha\FormChecker;
use App\Oha\FormReader;
use App\Oha\ReadForm;
use App\Oha\UnreadableForm;
use App\Support\Audit;
use App\Support\FileVault;
use Illuminate\Support\Facades\DB;

/**
 * Takes a completed OHA form for an assessment: stores the file unchanged,
 * reads and checks it, and records the answers and findings.
 *
 * A refused form (wrong movement, not the OHA form, blank) is still kept, with
 * its findings, so there is a record of what was tried. It cannot be submitted.
 *
 * A corrected form may be uploaded after approval, until the Board Chairperson signs
 * the ODP. It goes back to the Administrators, who are told; until they approve it,
 * everyone keeps seeing the approved form and its recorded score.
 */
final class UploadForm
{
    use EnforcesPolicy, LocksArtefact, RecordsFormChecks;

    public function __construct(
        private readonly FormReader $reader,
        private readonly FormChecker $checker,
    ) {}

    public function handle(Artefact $form, string $sourcePath, string $originalName, User $uploader): FormUpload
    {
        $this->ensure($uploader, 'upload', $form);

        $assessment = $form->assessment;
        $movement = $assessment->movement;

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new WorkflowRuleBroken('The OHA form must be an Excel workbook (.xlsx).');
        }
        if (filesize($sourcePath) > (int) config('oha.max_upload_kb') * 1024) {
            throw new WorkflowRuleBroken('The file is larger than '.((int) config('oha.max_upload_kb') / 1024).' MB.');
        }

        [$read, $result] = $this->readAndCheck($sourcePath, $movement);
        $stored = FileVault::store($sourcePath, "forms/{$assessment->id}", 'xlsx');

        return DB::transaction(function () use ($form, $assessment, $uploader, $originalName, $read, $result, $stored): FormUpload {
            $form = $this->lock($form);
            $this->ensure($uploader, 'upload', $form);

            $upload = $form->uploads()->create($stored + [
                'original_name' => $originalName,
                'uploaded_by' => $uploader->id,
                'uploaded_at' => now(),
                'answers' => $read->answers ?? [],
                'form_meta' => $this->meta($read, $result),
                'printed_totals' => $read?->printedTotals ?: null,
            ]);

            $this->recordFindings($upload, $result);

            // A fresh upload is a fresh read: its own findings, and no acknowledgement carried over.
            $from = $form->state;
            $this->settle($form, $result, $uploader, 'uploaded a new file for');

            Audit::record($uploader, 'form.uploaded', $upload, $assessment, $from, $form->state, payload: [
                'file' => $originalName,
                'sha256' => $stored['sha256'],
                'accepted' => $result->ok,
                'points' => $result->points,
                'gaps' => count($result->withSeverity(FindingSeverity::Missing)),
                'read_from_words' => count($read->interpreted ?? []),
            ]);

            return $upload;
        });
    }

    /**
     * @return array{0: ReadForm|null, 1: CheckResult}
     */
    private function readAndCheck(string $path, Movement $movement): array
    {
        try {
            $read = $this->reader->read($path);
        } catch (UnreadableForm $e) {
            return [null, new CheckResult(false, [new Finding(FindingSeverity::Error, FindingRule::Unreadable, $e->getMessage(),
                location: 'Workbook', hint: 'Check it opens in Excel, and upload the .xlsx the movement returned.')], null, [], [])];
        }

        return [$read, $this->checker->check($read, $movement)];
    }
}
