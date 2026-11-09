<?php

namespace App\Actions\Evidence;

use App\Actions\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\Category;
use App\Models\Evidence;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\EvidenceQuestions;
use App\Support\Audit;
use App\Support\FileVault;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * Adds evidence to an assessment's evidence library: a file (stored once, never
 * overwritten) or a link, filed under one OHA category (or General), with the form
 * questions and ODP tasks it backs. Each question takes the kind of evidence that proves
 * it: a website is a link, a policy is a document. When the movement's National General
 * Secretary adds it, the movement's assessors are told, in the app and by email.
 */
final class UploadEvidence
{
    use EnforcesPolicy;

    /**
     * Give either a file ($sourcePath and $originalName) or a $url.
     *
     * @param  list<string>  $questionCodes  e.g. Q610
     * @param  list<string>  $tasks  ODP task numbers, e.g. 5.2
     */
    public function handle(
        User $by,
        Assessment $assessment,
        ?string $sourcePath,
        ?string $originalName,
        ?string $title = null,
        ?Category $category = null,
        array $questionCodes = [],
        array $tasks = [],
        bool $share = false,
        ?string $url = null,
    ): Evidence {
        $this->ensure($by, 'upload', [Evidence::class, $assessment]);

        $url = $url !== null && trim($url) !== '' ? trim($url) : null;
        $isFile = $sourcePath !== null && $originalName !== null;
        if ($isFile === ($url !== null)) {
            throw new WorkflowRuleBroken('Add either a file or a link.');
        }

        if ($url !== null) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1 || mb_strlen($url) > 2000) {
                throw new WorkflowRuleBroken('Paste the full link, starting with https:// or http://.');
            }
            $format = null;
        } else {
            $format = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
            if (! in_array($format, Evidence::FORMATS, true)) {
                throw new WorkflowRuleBroken('This kind of file cannot be added. Add a document, spreadsheet, presentation, image or text file (PDF, Word, Excel, PowerPoint, JPG, PNG…).');
            }
            if (filesize((string) $sourcePath) > (int) config('oha.max_upload_kb') * 1024) {
                throw new WorkflowRuleBroken('The file is larger than '.((int) config('oha.max_upload_kb') / 1024).' MB.');
            }
        }

        $questionCodes = array_values(array_unique($questionCodes));
        foreach ($questionCodes as $code) {
            if (EvidenceQuestions::label($code) === null) {
                throw new WorkflowRuleBroken("{$code} is not a question evidence can back.");
            }
            if ($category !== null && EvidenceQuestions::categoryOf($code) !== $category->code) {
                throw new WorkflowRuleBroken(EvidenceQuestions::shown($code)." is not a {$category->name} question. File the evidence under that question’s category, or under General.");
            }
            $accepts = EvidenceQuestions::accepts($code);
            if ($url !== null && $accepts === EvidenceQuestions::DOCUMENT) {
                throw new WorkflowRuleBroken(EvidenceQuestions::shown($code).' '.EvidenceQuestions::label($code).' is proven by a document, not a link: '.EvidenceQuestions::needed($code));
            }
            if ($url === null && $accepts === EvidenceQuestions::LINK) {
                throw new WorkflowRuleBroken(EvidenceQuestions::shown($code).' '.EvidenceQuestions::label($code).' is proven by a link, not a file: '.EvidenceQuestions::needed($code));
            }
        }

        $tasks = array_values(array_unique(array_map('trim', $tasks)));
        foreach ($tasks as $task) {
            if (preg_match('/^([1-9])\.([1-9][0-9]?)$/', $task, $m) !== 1) {
                throw new WorkflowRuleBroken("“{$task}” is not an ODP task number. Write it as on the ODP, for example 5.2.");
            }
            if ($category !== null && (int) $m[1] !== $category->form_order) {
                throw new WorkflowRuleBroken("Task {$task} is under ODP priority {$m[1]}, not {$category->name} (priority {$category->form_order}).");
            }
        }

        $sha256 = $isFile ? (string) hash_file('sha256', (string) $sourcePath) : null;
        $existing = Evidence::query()->where('assessment_id', $assessment->id)
            ->when($isFile, fn ($q) => $q->where('sha256', $sha256), fn ($q) => $q->where('url', $url))->first();
        if ($existing !== null) {
            throw new WorkflowRuleBroken(($isFile ? 'This file' : 'This link')." is already in the library, as “{$existing->title}”.");
        }

        if ($share) {
            $this->ensure($by, 'shareFor', [Evidence::class, $assessment->movement]);
        }

        $title = $title !== null && trim($title) !== ''
            ? trim($title)
            : ($isFile ? pathinfo((string) $originalName, PATHINFO_FILENAME) : (string) (parse_url((string) $url, PHP_URL_HOST) ?: $url));

        $evidence = DB::transaction(function () use ($by, $assessment, $sourcePath, $originalName, $url, $title, $category, $questionCodes, $tasks, $share, $format): Evidence {
            $stored = $format !== null ? FileVault::store((string) $sourcePath, "evidence/{$assessment->id}", $format) : null;
            $evidence = Evidence::query()->create([
                'assessment_id' => $assessment->id,
                'category_id' => $category?->id,
                'title' => mb_substr($title, 0, 200),
                'url' => $url,
                'format' => $format,
                'disk' => $stored['disk'] ?? null,
                'path' => $stored['path'] ?? null,
                'original_name' => $stored !== null ? $originalName : null,
                'size_bytes' => $stored['size_bytes'] ?? null,
                'sha256' => $stored['sha256'] ?? null,
                'uploaded_by' => $by->id,
                'uploaded_at' => now(),
                'shared_at' => $share ? now() : null,
                'shared_by' => $share ? $by->id : null,
            ]);

            DB::table('evidence_questions')->insert(array_map(fn (string $code) => ['evidence_id' => $evidence->id, 'question_code' => $code], $questionCodes));
            DB::table('evidence_odp_tasks')->insert(array_map(fn (string $task) => ['evidence_id' => $evidence->id, 'task' => $task], $tasks));

            Audit::record($by, 'evidence.added', $evidence, $assessment, payload: array_filter([
                'title' => $evidence->title, 'file' => $originalName, 'link' => $url, 'category' => $category->code ?? 'general',
                'questions' => $questionCodes, 'odp_tasks' => $tasks, 'shared' => $share ?: null,
            ]));

            return $evidence;
        });

        if ($by->isGeneralSecretaryOf($assessment->movement)) {
            Notify::send(Notify::assessorsOf($assessment), new WorkflowNotice(
                "New evidence from {$assessment->movement->name}",
                "{$by->name}, National General Secretary, added “{$evidence->title}” to the evidence for the {$assessment->period_label} assessment.",
                route('evidence.index', ['movement' => $assessment->movement, 'assessment' => $assessment->id], false),
            ), except: $by);
        }

        return $evidence;
    }
}
