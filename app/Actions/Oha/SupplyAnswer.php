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
use App\Oha\FormChecker;
use App\Oha\FormDefinition;
use App\Oha\FormReader;
use App\Oha\Interpreter;
use App\Oha\Question;
use App\Support\Audit;
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
    ) {}

    /**
     * @param  string|array<string, string|null>  $input  one answer, or a percentage per line of a group
     */
    public function handle(FormFinding $finding, User $user, string|array $input): FormUpload
    {
        $this->ensure($user, 'answer', $finding);

        $upload = $finding->formUpload;
        $ref = $finding->ref ?? 'q:'.$finding->question_code;
        $value = $this->value($ref, $input, $upload);

        return $this->recheck($upload, $user, function (array $supplied) use ($ref, $value, $user): array {
            $supplied[$ref] = ['value' => $value, 'by' => $user->id, 'by_name' => $user->name, 'at' => now()->toIso8601String()];

            return $supplied;
        }, 'typed an answer in', 'form.answer_supplied', ['ref' => $ref, 'value' => $value, 'finding' => $finding->message]);
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

        return DB::transaction(function () use ($form, $upload, $user, $supplied, $read, $result, $what, $action, $payload, $assessment): FormUpload {
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
    }

    /**
     * What was typed, as the question asks for it; refused with a plain reason otherwise.
     *
     * @param  string|array<string, string|null>  $input
     * @return int|float|string|array<string, int|float>
     */
    private function value(string $ref, string|array $input, FormUpload $upload): int|float|string|array
    {
        [$kind, $key] = explode(':', $ref, 2) + [1 => ''];

        return match ($kind) {
            'q' => $this->answer(Interpreter::question($key) ?? throw new WorkflowRuleBroken('This question is not on the form.'), $input, $upload),
            'g' => $this->shares($key, $input),
            'c' => $this->comment($input),
            's' => $this->yesNo($input),
            default => throw new WorkflowRuleBroken('This cannot be typed in: upload a corrected form instead.'),
        };
    }

    /**
     * @param  string|array<string, string|null>  $input
     */
    private function answer(Question $q, string|array $input, FormUpload $upload): int|float|string
    {
        $text = is_array($input) ? '' : trim($input);
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
