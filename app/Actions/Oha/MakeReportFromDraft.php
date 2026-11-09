<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\Document;
use App\Models\ReportDraft;
use App\Models\User;
use App\Oha\Report\WordReport;

/**
 * Makes the corrected draft into the Word report, on the official AAYMCA template, and
 * saves it as the report's next version: from then on it is a report like any other (the
 * report check, approval, sending back, its versions), with nothing saying it was drafted
 * in the platform. Whoever makes it confirms they have read every section first.
 */
final class MakeReportFromDraft
{
    use EnforcesPolicy;

    public function __construct(
        private readonly WordReport $word,
        private readonly UploadReportVersion $upload,
    ) {}

    /**
     * @param  array<string, string>  $replacing  what the audit trail keeps when it replaces a version (ReplaceGuard)
     */
    public function handle(ReportDraft $draft, User $user, bool $readEverySection, ?string $note = null, array $replacing = []): Document
    {
        $report = $draft->artefact;
        $this->ensure($user, 'upload', $report);
        if (! $readEverySection) {
            throw new WorkflowRuleBroken('Tick the box to confirm you have read and checked every section.');
        }

        $path = $this->word->write($draft->sections);
        try {
            return $this->upload->handle($report, $path, self::fileName($report->assessment), $user, $note, $replacing);
        } finally {
            @unlink($path);
        }
    }

    /** "Zambia YMCA OHA Report February 2026.docx". */
    public static function fileName(Assessment $assessment): string
    {
        $name = preg_replace('/[^\pL\pN ._-]+/u', '', $assessment->movement->name.' OHA Report '.$assessment->assessed_on->format('F Y'));

        return trim((string) $name).'.docx';
    }
}
