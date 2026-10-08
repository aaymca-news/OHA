<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\StoresVersions;
use App\Enums\ArtefactKind;
use App\Enums\DocumentFormat;
use App\Enums\DocumentSource;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Category;
use App\Models\Document;
use App\Models\User;
use App\Oha\Finding;
use App\Oha\Report\DocxEditor;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use App\Oha\Report\ReportFixes;
use App\Oha\Report\ReportReader;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fixes what the report check found by writing it into the report: the score and the
 * period from what the platform already knows (the OHA form, the assessment), or a
 * missing section, the author, a category's analysis or its opportunities, as typed.
 *
 * Each fix is written into a copy of the newest version, saved as a new version made by
 * the platform; that is what is previewed and downloaded. Every earlier version is kept.
 * Like any new version, it goes back to the Administrators if the report was approved.
 * A PDF cannot be written into: upload the report as Word to fix it here.
 */
final class FixReport
{
    use StoresVersions;

    public function __construct(
        private readonly ReportReader $reader,
        private readonly ReportChecker $checker,
    ) {}

    /**
     * One fix, for the finding with this reference.
     *
     * @param  array{value?: string|null, growth?: string|null}  $input
     */
    public function handle(Artefact $report, User $user, string $ref, array $input = []): Document
    {
        [$version, $findings] = $this->current($report, $user);
        $finding = collect($findings)->first(fn (Finding $f) => $f->ref === $ref)
            ?? throw new WorkflowRuleBroken('That is no longer flagged in the newest version of the report.');
        $fix = ReportFixes::for($finding) ?? throw new WorkflowRuleBroken('This cannot be fixed by writing into the report. Mark it as reviewed instead.');

        return $this->write($report, $user, $version, function (DocxEditor $doc) use ($report, $finding, $fix, $input): string {
            return $this->apply($doc, $report, $finding, $fix, $input);
        });
    }

    /** Everything that can be written in from the OHA form and the assessment, in one new version. */
    public function fromForm(Artefact $report, User $user): Document
    {
        [$version, $findings] = $this->current($report, $user);
        $fixable = array_values(array_filter($findings, fn (Finding $f) => (ReportFixes::for($f)['kind'] ?? null) === 'form'));
        if ($fixable === []) {
            throw new WorkflowRuleBroken('Nothing flagged can be filled in from the OHA form.');
        }

        return $this->write($report, $user, $version, function (DocxEditor $doc) use ($report, $fixable): string {
            return implode(' ', array_map(fn (Finding $f) => $this->apply($doc, $report, $f, (array) ReportFixes::for($f), []), $fixable));
        });
    }

    /**
     * The newest version, which must be a Word file, and what the check finds in it now.
     *
     * @return array{0: Document, 1: list<Finding>}
     */
    private function current(Artefact $report, User $user): array
    {
        if ($report->kind !== ArtefactKind::Report) {
            throw new WorkflowRuleBroken('Only the report is fixed here.');
        }
        $this->ensure($user, 'fix', $report);

        $version = $report->latestVersion()->first() ?? throw new WorkflowRuleBroken('Upload the report first.');
        if ($version->format !== DocumentFormat::Docx) {
            throw new WorkflowRuleBroken('This version is a PDF, which cannot be written into. Upload the report as a Word (.docx) file to fix it here.');
        }
        if ($version->extracted === null) {
            throw new WorkflowRuleBroken('This version could not be read, so it cannot be checked or fixed.');
        }

        return [$version, $this->checker->check(ReadReport::fromArray($version->extracted), $report->assessment)['findings']];
    }

    /**
     * @param  callable(DocxEditor): string  $edit  returns what was done, for the version's note
     */
    private function write(Artefact $report, User $user, Document $version, callable $edit): Document
    {
        $copy = tempnam(sys_get_temp_dir(), 'rep').'.docx';
        file_put_contents($copy, Storage::disk($version->disk)->get($version->path));

        try {
            try {
                $doc = DocxEditor::open($copy);
            } catch (Throwable) {
                throw new WorkflowRuleBroken('This Word file could not be opened to write into. Upload a fresh copy of the report.');
            }
            $done = $edit($doc);
            $doc->save();

            $read = $this->reader->read($copy, 'docx');

            return $this->storeVersion($report, $copy, $version->original_name, $user, $user, 'fix', [
                'source' => DocumentSource::Platform,
                'note' => 'Fixed in the platform: '.$done,
                'extracted' => $read->toArray(),
                'changes' => ['from_version' => $version->versionNumber(), 'done' => $done],
            ], ['fixed' => $done, 'from_version' => $version->versionNumber()]);
        } finally {
            @unlink($copy);
        }
    }

    /**
     * Writes one fix into the document. Returns what was done, in a sentence.
     *
     * @param  array{kind: string, key: string, title?: string, guide?: string}  $fix
     * @param  array{value?: string|null, growth?: string|null}  $input
     */
    private function apply(DocxEditor $doc, Artefact $report, Finding $finding, array $fix, array $input): string
    {
        $assessment = $report->assessment;
        $baseline = $this->checker->baseline($assessment);
        $names = Category::query()->pluck('name', 'code');
        $figure = fn (float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        $typed = trim((string) ($input['value'] ?? ''));

        if ($fix['kind'] === 'form') {
            return match ($fix['key']) {
                'period' => $this->period($doc, $assessment->period_label, (int) $assessment->assessed_on->format('Y')),
                'score', 'score-fix' => $this->score($doc, $baseline, $figure, $report),
                'catscores' => $this->categoryScores($doc, $baseline, $names->all()),
                'catscore' => $this->categoryScore($doc, $baseline, $finding, $names->all()),
                default => throw new WorkflowRuleBroken('This cannot be filled in from the form.'),
            };
        }

        if ($fix['kind'] === 'author') {
            if (mb_strlen($typed) < 3) {
                throw new WorkflowRuleBroken('Type the names of those who wrote the report.');
            }
            $doc->addUnderTitle([['text' => 'Report by: '.$typed, 'kind' => 'normal']]);

            return 'added who wrote the report.';
        }

        if ($fix['kind'] === 'section') {
            $lines = $this->lines($typed, 'Write the section: at least a sentence.');
            $doc->addBeforeCategories([['text' => (string) ($fix['title'] ?? ''), 'kind' => 'heading'], ...$lines]);

            return 'added the section “'.$fix['title'].'”.';
        }

        $name = (string) ($names[$fix['key']] ?? $fix['key']);
        if ($fix['kind'] === 'category') {
            $analysis = $this->lines($typed, 'Write the analysis of '.$name.': at least a sentence.');
            $growth = $this->lines(trim((string) ($input['growth'] ?? '')), 'List at least one opportunity for growth for '.$name.'.', bullets: true);
            $doc->append([['text' => $name, 'kind' => 'heading'], ...$analysis, ['text' => 'Opportunities for growth:', 'kind' => 'bold'], ...$growth]);

            return 'added the analysis of '.$name.'.';
        }

        // The category's missing opportunities for growth, at the end of its own section.
        $growth = $this->lines($typed, 'List at least one opportunity for growth.', bullets: true);
        $doc->addToCategory($fix['key'], [['text' => 'Opportunities for growth:', 'kind' => 'bold'], ...$growth])
            ?: throw new WorkflowRuleBroken('The report has no section for '.$name.'.');

        return 'added the opportunities for growth in '.$name.'.';
    }

    private function period(DocxEditor $doc, string $label, int $year): string
    {
        $period = str_contains($label, (string) $year) ? $label : trim($label.' '.$year);
        $doc->addUnderTitle([['text' => 'Organisational Health Assessment report, period: '.$period, 'kind' => 'bold']]);

        return 'added the assessment period ('.$period.').';
    }

    /**
     * @param  array{pct: float, points: float, available: int, source: string, categories: array<string, float>}|null  $baseline
     * @param  callable(float): string  $figure
     */
    private function score(DocxEditor $doc, ?array $baseline, callable $figure, Artefact $report): string
    {
        $baseline ?? throw new WorkflowRuleBroken('The OHA form has no score yet to take it from.');
        $stated = $this->checker->statedScore(ReadReport::fromArray((array) $report->latestVersion()->first()?->extracted));
        $right = $figure($baseline['points']).'/'.$baseline['available'].' points ('.$figure($baseline['pct']).'%)';

        // A wrong figure where the report states the score is corrected in place; then the
        // form's score is stated under the title, where it is read first.
        $corrected = $stated['pct'] !== null
            && $doc->replace('/'.preg_quote($figure($stated['pct']), '/').'\s*%/', $figure($baseline['pct']).'%', near: '/overall/i');
        if ($stated['out_of'] !== null) {
            $doc->replace('/'.preg_quote($figure((float) $stated['points']), '/').'\s*\/\s*'.(int) $stated['out_of'].'\b/', $figure($baseline['points']).'/'.$baseline['available'], near: '/overall/i');
        }
        $doc->addUnderTitle([['text' => 'Overall score: '.$right.', from the '.($baseline['source'] === 'approved' ? 'approved' : 'uploaded').' OHA form.', 'kind' => 'normal']]);

        return 'stated the overall score from the OHA form, '.$right.($corrected ? ', and corrected the figure in the text.' : '.');
    }

    /**
     * @param  array{pct: float, points: float, available: int, source: string, categories: array<string, float>}|null  $baseline
     * @param  array<string, string>  $names
     */
    private function categoryScores(DocxEditor $doc, ?array $baseline, array $names): string
    {
        $baseline ?? throw new WorkflowRuleBroken('The OHA form has no scores yet to take them from.');
        $written = 0;
        foreach ($baseline['categories'] as $code => $pct) {
            if ($doc->addToCategory($code, [['text' => 'Score in this category: '.rtrim(rtrim(number_format($pct, 1), '0'), '.').'%, from the OHA form.', 'kind' => 'normal']])) {
                $written++;
            }
        }

        return $written > 0 ? 'wrote each category’s score from the OHA form into its section.' : throw new WorkflowRuleBroken('The report has no category sections to write the scores into.');
    }

    /**
     * @param  array{pct: float, points: float, available: int, source: string, categories: array<string, float>}|null  $baseline
     * @param  array<string, string>  $names
     */
    private function categoryScore(DocxEditor $doc, ?array $baseline, Finding $finding, array $names): string
    {
        [, , $code, $said] = explode(':', (string) $finding->ref) + [2 => '', 3 => ''];
        $pct = $baseline['categories'][$code] ?? throw new WorkflowRuleBroken('The OHA form has no score for this category.');
        $right = rtrim(rtrim(number_format($pct, 1), '0'), '.').'%';
        $name = $names[$code] ?? $code;

        if (! $doc->replace('/'.preg_quote($said, '/').'\s*%/', $right, category: $code)) {
            $doc->addToCategory($code, [['text' => 'Score in this category: '.$right.', from the OHA form.', 'kind' => 'normal']]);
        }

        return 'corrected the score of '.$name.' to '.$right.', from the OHA form.';
    }

    /**
     * Typed text as paragraphs: one per line; a line starting with -, * or • is a bullet.
     *
     * @return list<array{text: string, kind: string}>
     */
    private function lines(string $text, string $empty, bool $bullets = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $bullet = $bullets || preg_match('/^[-*•]\s*/u', $line) === 1;
            $lines[] = ['text' => trim((string) preg_replace('/^[-*•]\s*/u', '', $line)), 'kind' => $bullet ? 'bullet' : 'normal'];
        }
        if ($lines === [] || mb_strlen(implode(' ', array_column($lines, 'text'))) < 5) {
            throw new WorkflowRuleBroken($empty);
        }

        return $lines;
    }
}
