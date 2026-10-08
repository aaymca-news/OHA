<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Actions\Oha\Concerns\RecordsFormChecks;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\FormFinding;
use App\Models\FormUpload;
use App\Models\User;
use App\Oha\Answer;
use App\Oha\FindingFields;
use App\Oha\FormChecker;
use App\Oha\FormDefinition;
use App\Oha\FormReader;
use App\Oha\FormWorkbookWriter;
use App\Oha\Interpreter;
use App\Oha\Question;
use App\Support\Audit;
use App\Support\VersionPruner;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Types in the platform what the OHA form is missing, in the form its question asks for:
 * Yes or No, one of its options, a figure, the percentages of a group, the areas of
 * improvement, or a sign-off. An answer that could not be read, or was in the wrong unit,
 * is corrected the same way.
 *
 * The file is never changed. The typed answer is kept with the upload, with who typed it
 * and when, and the same file is checked again with it: the findings, the provisional
 * score and the data-quality checklist all follow. An approved form goes back to the
 * Administrators, who are told.
 */
final class SupplyAnswer
{
    use EnforcesPolicy, LocksArtefact, RecordsFormChecks;

    public function __construct(
        private readonly FormReader $reader,
        private readonly FormChecker $checker,
        private readonly FormWorkbookWriter $writer,
    ) {}

    /**
     * @param  string|array<string, string|null>  $input  one answer; or, by question code, several
     *                                                    answers or the percentages of a group
     */
    public function handle(FormFinding $finding, User $user, string|array $input): FormUpload
    {
        $this->ensure($user, 'answer', $finding);

        $upload = $finding->formUpload;
        $fields = FindingFields::for($finding) ?? throw new WorkflowRuleBroken('This cannot be settled by typing. Mark it as reviewed with a note instead.');
        $entries = $this->entries($fields, $input, $upload);

        return $this->recheck($upload, $user, function (array $supplied) use ($entries, $user): array {
            foreach ($entries as $ref => $value) {
                $supplied[$ref] = ['value' => $value, 'by' => $user->id, 'by_name' => $user->name, 'at' => now()->toIso8601String()];
            }

            return $supplied;
        }, 'typed an answer in', 'form.answer_supplied', ['answers' => $entries, 'finding' => $finding->message]);
    }

    /**
     * What was typed, by the reference it is kept under: "q:Q246" for an answer, "g:income"
     * for a group of percentages, "c:financial" for areas of improvement, "s:…" for a sign-off.
     *
     * @param  array{kind: string, key: string, codes: list<string>}  $fields
     * @param  string|array<string, string|null>  $input
     * @return array<string, int|float|string|array<string, int|float>>
     */
    private function entries(array $fields, string|array $input, FormUpload $upload): array
    {
        if ($fields['kind'] === 'answers') {
            $typed = is_array($input) ? $input : [$fields['codes'][0] => $input];
            $entries = [];
            foreach ($fields['codes'] as $code) {
                $text = trim((string) ($typed[$code] ?? ''));
                if ($text === '') {
                    continue;
                }
                $q = Interpreter::question($code) ?? throw new WorkflowRuleBroken('This question is not on the form.');
                try {
                    $entries['q:'.$code] = $this->answer($q, $text, $upload);
                } catch (WorkflowRuleBroken $e) {
                    throw new WorkflowRuleBroken(count($fields['codes']) > 1 ? $q->displayCode().': '.$e->getMessage() : $e->getMessage());
                }
            }
            if ($entries === []) {
                throw new WorkflowRuleBroken(count($fields['codes']) > 1 ? 'Type at least one of the answers.' : 'Type the answer.');
            }

            return $entries;
        }

        return match ($fields['kind']) {
            'shares' => ['g:'.$fields['key'] => $this->shares($fields['key'], is_array($input) ? $input : [])],
            'comment' => ['c:'.$fields['key'] => $this->comment($input)],
            'signoff' => ['s:'.$fields['key'] => $this->yesNo($input)],
            default => throw new WorkflowRuleBroken('This cannot be typed in: upload a corrected form instead.'),
        };
    }

    /** Takes back an answer typed in the platform: the form is checked again without it. */
    public function withdraw(FormUpload $upload, string $ref, User $user): FormUpload
    {
        $this->ensure($user, 'supply', $upload);

        if (! isset(($upload->supplied ?? [])[$ref])) {
            throw new WorkflowRuleBroken('That answer is not one typed in the platform.');
        }
        $previous = $upload->supplied[$ref]['value'];

        return $this->recheck($upload, $user, function (array $supplied) use ($ref): array {
            unset($supplied[$ref]);

            return $supplied;
        }, 'took back an answer typed in', 'form.answer_withdrawn', ['ref' => $ref, 'previous' => $previous]);
    }

    /**
     * When the Board Chairperson signs the ODP: the approved upload goes back to the
     * answers it was approved with, dropping any typed since. Its file already carries
     * those (see ApproveArtefact), so the copy written since goes. Part of signing, not a
     * person's step, so no policy is checked. Returns whether anything was dropped.
     */
    public function restoreApproved(FormUpload $upload): bool
    {
        $approved = $upload->approved_supplied ?? [];
        if ($approved == ($upload->supplied ?? []) && ! $upload->hasEdits()) {
            return false;
        }

        $copy = tempnam(sys_get_temp_dir(), 'oha');
        file_put_contents($copy, Storage::disk($upload->disk)->get($upload->path));
        try {
            $read = $this->reader->read($copy)->withSupplied($approved);
        } finally {
            @unlink($copy);
        }
        $result = $this->checker->check($read, $upload->artefact->assessment->movement);

        $previous = $upload->findings()->get();
        $upload->findings()->delete();
        [$disk, $path] = [$upload->edited_disk, $upload->edited_path];
        $upload->update([
            'answers' => $read->answers,
            'form_meta' => $this->meta($read, $result),
            'supplied' => $approved === [] ? null : $approved,
            'edited_disk' => null, 'edited_path' => null, 'edited_sha256' => null, 'edited_size_bytes' => null,
        ]);
        $this->recordFindings($upload, $result, $previous);
        VersionPruner::removeFileIfUnused($disk, $path);

        return true;
    }

    /**
     * Checks the same file again with the typed answers on top, and records the result.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     * @param  array<string, mixed>  $payload
     */
    private function recheck(FormUpload $upload, User $user, Closure $change, string $what, string $action, array $payload): FormUpload
    {
        $form = $upload->artefact;
        $assessment = $form->assessment;
        $supplied = $change($upload->supplied ?? []);

        $copy = tempnam(sys_get_temp_dir(), 'oha');
        file_put_contents($copy, Storage::disk($upload->disk)->get($upload->path));
        try {
            $read = $this->reader->read($copy)->withSupplied($supplied);
        } finally {
            @unlink($copy);
        }
        $result = $this->checker->check($read, $assessment->movement);

        $upload = DB::transaction(function () use ($form, $upload, $user, $supplied, $read, $result, $what, $action, $payload, $assessment): FormUpload {
            $form = $this->lock($form);
            $this->ensure($user, 'supply', $upload);

            $previous = $upload->findings()->get();
            $upload->findings()->delete();
            $upload->update([
                'answers' => $read->answers,
                'form_meta' => $this->meta($read, $result),
                'supplied' => $supplied === [] ? null : $supplied,
            ]);
            $this->recordFindings($upload, $result, $previous);

            $from = $form->state;
            $this->settle($form, $result, $user, $what);

            Audit::record($user, $action, $upload, $assessment, $from, $form->state, payload: $payload + ['points' => $result->points]);

            return $upload->refresh();
        });

        $this->writeCorrectedCopy($upload);

        return $upload->refresh();
    }

    /**
     * The typed answers written into a copy of the workbook: what is previewed and
     * downloaded. The copy before is removed when nothing else uses it.
     */
    private function writeCorrectedCopy(FormUpload $upload): void
    {
        $before = $upload->edited_path !== null ? [$upload->edited_disk, $upload->edited_path] : null;
        $stored = $this->writer->write($upload);

        $upload->update($stored !== null
            ? ['edited_disk' => $stored['disk'], 'edited_path' => $stored['path'], 'edited_sha256' => $stored['sha256'], 'edited_size_bytes' => $stored['size_bytes']]
            : ['edited_disk' => null, 'edited_path' => null, 'edited_sha256' => null, 'edited_size_bytes' => null]);

        if ($before !== null && $before[1] !== ($stored['path'] ?? null)
            && ! FormUpload::query()->where('edited_disk', $before[0])->where('edited_path', $before[1])->exists()) {
            Storage::disk((string) $before[0])->delete((string) $before[1]);
        }
    }

    /** One answer, as its question asks for it; refused with a plain reason otherwise. */
    private function answer(Question $q, string $input, FormUpload $upload): int|float|string
    {
        $text = trim($input);
        if ($text === '') {
            throw new WorkflowRuleBroken($q->type === 'choice' ? 'Choose an answer.' : 'Type the answer.');
        }

        $read = Interpreter::read($q, $text, Interpreter::currencyCode($upload->answers['Q208'] ?? null));
        if (isset($read['unit'])) {
            throw new WorkflowRuleBroken('That is '.$read['unit'].'; the form asks for '.($read['expected'] ?? Interpreter::expected($q)).'.');
        }
        if (! isset($read['value'])) {
            throw new WorkflowRuleBroken(match ($q->type) {
                'choice' => 'Choose one of: '.implode(', ', $q->options ?? []).'.',
                'money' => 'Type the amount as one figure, for example 10005.',
                'pct' => 'Type the percentage as one figure, for example 25 for 25%.',
                default => 'Type '.Interpreter::expected($q).' as one figure, for example 12.',
            });
        }
        $value = $read['value'];
        if ($q->type === 'pct' && ($value < 0 || $value > 100)) {
            throw new WorkflowRuleBroken('A percentage is between 0 and 100.');
        }
        if ($q->type === 'number' && $value < 0) {
            throw new WorkflowRuleBroken('This cannot be less than 0.');
        }
        if ($q->type === 'text' && mb_strlen((string) $value) > 2000) {
            throw new WorkflowRuleBroken('Keep the answer under 2,000 characters.');
        }

        return $value;
    }

    /**
     * The percentages of a group (income by source, expenditure by type): each line 0–100,
     * blank for 0, adding up to 100.
     *
     * @param  string|array<string, string|null>  $input
     * @return array<string, int|float>
     */
    private function shares(string $group, string|array $input): array
    {
        $codes = match ($group) {
            'income' => FormDefinition::INCOME,
            'expense' => FormDefinition::EXPENSE,
            default => throw new WorkflowRuleBroken('This group is not on the form.'),
        };
        $input = is_array($input) ? $input : [];

        $shares = [];
        foreach ($codes as $code) {
            $text = trim((string) ($input[$code] ?? ''));
            if ($text === '') {
                continue;
            }
            $read = Interpreter::read(Interpreter::question($code) ?? throw new WorkflowRuleBroken('This question is not on the form.'), $text);
            $value = $read['value'] ?? null;
            if (! is_int($value) && ! is_float($value) || $value < 0 || $value > 100) {
                throw new WorkflowRuleBroken($code.': type a percentage between 0 and 100, for example 25.');
            }
            $shares[$code] = $value;
        }

        if ($shares === []) {
            throw new WorkflowRuleBroken('Type at least one percentage. Leave a line blank if it is 0.');
        }
        $sum = array_sum($shares);
        if (abs($sum - 100) > 2) {
            throw new WorkflowRuleBroken('These add up to '.round($sum, 1).'%. Together they should make 100%.');
        }

        return $shares;
    }

    /**
     * @param  string|array<string, string|null>  $input
     */
    private function comment(string|array $input): string
    {
        $text = is_array($input) ? '' : trim($input);
        if (mb_strlen($text) < 10) {
            throw new WorkflowRuleBroken('Write the areas of improvement the movement gave, in a sentence or more.');
        }

        return mb_substr($text, 0, 2000);
    }

    /**
     * @param  string|array<string, string|null>  $input
     */
    private function yesNo(string|array $input): string
    {
        $text = is_array($input) ? '' : trim($input);

        return match (true) {
            Answer::isYes($text) => 'Yes',
            Answer::isNo($text) => 'No',
            default => throw new WorkflowRuleBroken('Choose Yes or No.'),
        };
    }
}
