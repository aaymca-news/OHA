<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Enums\ArtefactState;
use App\Enums\FindingSeverity;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Category;
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
 */
final class UploadForm
{
    use EnforcesPolicy, LocksArtefact;

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

            $from = $form->state;
            // A fresh upload is a fresh read: its own findings, and no acknowledgement carried over.
            $form->update([
                'state' => $result->ok ? ArtefactState::Ready : ArtefactState::RulesFailed,
                'gap_ack' => false,
                'gap_ack_reason' => null,
            ]);

            Audit::record($uploader, 'form.uploaded', $upload, $assessment, $from, $form->state, payload: [
                'file' => $originalName,
                'sha256' => $stored['sha256'],
                'accepted' => $result->ok,
                'points' => $result->points,
                'gaps' => count($result->withSeverity(FindingSeverity::Missing)),
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

    /**
     * @return array<string, mixed>
     */
    private function meta(?ReadForm $read, CheckResult $result): array
    {
        if ($read === null) {
            return [];
        }

        return [
            'notes' => array_filter($read->notes, fn ($v) => $v !== null),
            'comments' => array_map(fn ($c) => $c['text'], $read->comments),
            'cover' => $result->cover,
            'sheet_names' => $read->sheetNames,
            'missing_sheets' => $read->missingCategorySheets(),
            'points_at_stake' => $result->pointsAtStake,
        ];
    }

    private function recordFindings(FormUpload $upload, CheckResult $result): void
    {
        $categories = Category::query()->pluck('id', 'code');

        foreach ($result->findings as $finding) {
            $upload->findings()->create([
                'severity' => $finding->severity,
                'rule' => $finding->rule->value,
                'ref' => $finding->ref,
                'question_code' => $finding->questionCode,
                'category_id' => $finding->categoryCode !== null ? $categories[$finding->categoryCode] ?? null : null,
                'location' => $finding->location !== '' ? $finding->location : null,
                'message' => $finding->message,
                'hint' => $finding->hint !== '' ? $finding->hint : null,
                'points_at_stake' => $finding->pointsAtStake,
                'dqa_dimension' => $finding->rule->dimension(),
            ]);
        }
    }
}
