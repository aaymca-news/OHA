<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Enums\FindingSeverity;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\Category;
use App\Models\FollowUpWindow;
use App\Models\RescoreUpload;
use App\Models\User;
use App\Oha\FollowUpSchedule;
use App\Oha\FormChecker;
use App\Oha\FormDefinition;
use App\Oha\FormReader;
use App\Oha\Interpreter;
use App\Oha\Rescoring;
use App\Oha\TypedAnswer;
use App\Oha\UnreadableForm;
use App\Support\Audit;
use App\Support\FileVault;
use Illuminate\Support\Facades\DB;

/**
 * Re-scores the answers during an open follow-up (decided 12 Nov 2026), two ways, by the
 * movement's assessors, an Administrator or the Super Administrator:
 *
 *   change  one answer on the site (or the income percentages together), with the reason:
 *           read exactly as the Excel form's answer would be (TypedAnswer)
 *   undo    an answer changed, set back to the starting baseline's
 *   upload  the movement's re-filled Excel form: checked as in Stage 1 (the right form,
 *           the right movement), and its answers become the answers re-scored
 *
 * The answers are scored by the form's own formulas, so the site and the Excel form never
 * disagree. Each change is kept with what it was, why, who and how. No separate approval:
 * closing the follow-up records them as its baseline.
 */
final class Rescore
{
    use EnforcesPolicy;

    public function __construct(
        private readonly FormReader $reader,
        private readonly FormChecker $checker,
    ) {}

    /**
     * @param  string  $ref  "q:Q412" for one answer, "g:income" for the income percentages
     * @param  string|array<string, string|null>  $input
     */
    public function change(Assessment $assessment, User $by, string $ref, string|array $input, string $reason): FollowUpWindow
    {
        $this->ensure($by, 'rescore', $assessment);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new WorkflowRuleBroken('Say why the answer changed, for example: “The board approved the policy in March.”');
        }
        $followUp = $this->openFollowUp($assessment);

        [$kind, $key] = explode(':', $ref, 2) + [1 => ''];
        if ($kind === 'g' && $key === 'income') {
            $shares = TypedAnswer::shares('income', is_array($input) ? $input : []);
            $new = array_combine(FormDefinition::INCOME, array_map(fn (string $code) => $shares[$code] ?? null, FormDefinition::INCOME));
        } elseif ($kind === 'q' && in_array($key, Rescoring::editable(), true)) {
            $q = Interpreter::question($key) ?? throw new WorkflowRuleBroken('This question is not on the form.');
            $currency = Interpreter::currencyCode(Rescoring::answers($assessment, $followUp)['Q208'] ?? null);
            $new = [$key => TypedAnswer::one($q, is_string($input) ? $input : '', $currency)];
        } else {
            throw new WorkflowRuleBroken('This answer does not change the score: it is not re-scored here.');
        }

        return $this->apply($assessment, $by, $followUp, function (array $start, array $answers, array $recorded) use ($new, $reason, $by): array {
            foreach ($new as $code => $value) {
                $answers[$code] = $value;
                $recorded[$code] = self::entry($reason, $by, 'site');
            }

            return [$answers, $recorded];
        }, 'baseline.answer_changed', ['answers' => $new, 'reason' => $reason]);
    }

    /** Sets an answer back to the starting baseline's (the income percentages together). */
    public function undo(Assessment $assessment, User $by, string $code): FollowUpWindow
    {
        $this->ensure($by, 'rescore', $assessment);
        $followUp = $this->openFollowUp($assessment);
        $codes = in_array($code, FormDefinition::INCOME, true) ? FormDefinition::INCOME : [$code];

        return $this->apply($assessment, $by, $followUp, function (array $start, array $answers, array $recorded) use ($codes): array {
            foreach ($codes as $one) {
                $answers[$one] = $start[$one] ?? null;
                unset($recorded[$one]);
            }

            return [$answers, $recorded];
        }, 'baseline.answer_restored', ['codes' => $codes]);
    }

    /** The re-filled Excel form: its answers become the answers re-scored. */
    public function upload(Assessment $assessment, User $by, string $sourcePath, string $originalName, ?string $note = null): RescoreUpload
    {
        $this->ensure($by, 'rescore', $assessment);
        $followUp = $this->openFollowUp($assessment);
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null;

        try {
            $read = $this->reader->read($sourcePath);
        } catch (UnreadableForm $e) {
            throw new WorkflowRuleBroken($e->getMessage().' Check it opens in Excel, and upload the .xlsx the movement returned.');
        }
        $result = $this->checker->check($read, $assessment->movement);
        if (! $result->ok) {
            $error = $result->withSeverity(FindingSeverity::Error)[0] ?? null;
            throw new WorkflowRuleBroken(($error !== null ? $error->message.' '.$error->hint : 'This file cannot be read as the OHA form.'));
        }
        $missing = $read->missingCategorySheets();
        if ($missing !== []) {
            $names = Category::query()->whereIn('code', $missing)->orderBy('form_order')->pluck('name')->all();
            throw new WorkflowRuleBroken('The form is missing the '.implode(', ', $names).' '.(count($names) === 1 ? 'sheet' : 'sheets')
                .'. Upload the complete form: every answer is re-scored from it.');
        }

        $stored = FileVault::store($sourcePath, "documents/{$assessment->id}/form/follow-ups", 'xlsx');
        $reason = $note ?? 'From the re-uploaded form “'.$originalName.'”.';
        $changed = [];

        $this->apply($assessment, $by, $followUp, function (array $start, array $answers, array $recorded) use ($read, $reason, $by, $originalName, &$changed): array {
            $now = $read->answers;
            $changed = Rescoring::differences($answers, $now);
            foreach (Rescoring::differences($start, $now) as $code) {
                // An answer already changed to the same value keeps the reason given then.
                if (! isset($recorded[$code]) || ! Rescoring::same($answers[$code] ?? null, $now[$code] ?? null)) {
                    $recorded[$code] = self::entry($reason, $by, 'form') + ['file' => $originalName];
                }
            }

            return [$now, $recorded];
        }, 'baseline.form_reuploaded', ['file' => $originalName, 'sha256' => $stored['sha256']]);

        $upload = RescoreUpload::query()->create($stored + [
            'assessment_id' => $assessment->id,
            'follow_up_number' => $followUp,
            'original_name' => $originalName,
            'note' => $note,
            'changed' => $changed,
            'uploaded_by' => $by->id,
            'uploaded_at' => now(),
        ]);

        return $upload;
    }

    /**
     * Changes the answers being re-scored, under a lock on the follow-up's window.
     *
     * @param  callable(array<string, mixed>, array<string, mixed>, array<string, array<string, mixed>>): array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}  $change
     * @param  array<string, mixed>  $payload
     */
    private function apply(Assessment $assessment, User $by, int $followUp, callable $change, string $action, array $payload): FollowUpWindow
    {
        return DB::transaction(function () use ($assessment, $by, $followUp, $change, $action, $payload): FollowUpWindow {
            $window = FollowUpWindow::query()->where('assessment_id', $assessment->id)->where('number', $followUp)->lockForUpdate()->firstOrFail();
            $start = Rescoring::startingPoint($assessment, $followUp)->answers
                ?? throw new WorkflowRuleBroken('This follow-up has no baseline to start from. Ask an Administrator.');

            [$answers, $recorded] = $change($start, $window->answers ?? $start, $window->answer_changes ?? []);
            $changes = Rescoring::changes($recorded, $start, $answers);
            $window->forceFill([
                'answers' => $answers,
                // Kept without "from" and "to": both are read from the answers each time.
                'answer_changes' => $changes === [] ? null : array_map(fn (array $c) => array_diff_key($c, ['from' => 0, 'to' => 0]), $changes),
            ])->save();
            Audit::record($by, $action, $window, $assessment, payload: ['follow_up' => $followUp] + $payload);

            return $window;
        });
    }

    /** The follow-up open now (rescore's policy has made sure there is one). */
    private function openFollowUp(Assessment $assessment): int
    {
        $next = FollowUpSchedule::next($assessment);
        if ($next === null || $next['state'] !== FollowUpSchedule::OPEN) {
            throw new WorkflowRuleBroken('Open the follow-up first: the answers are re-scored while a follow-up is open.');
        }

        return $next['number'];
    }

    /**
     * @return array{reason: string, by: int, by_name: string, at: string, via: string}
     */
    private static function entry(string $reason, User $by, string $via): array
    {
        return ['reason' => $reason, 'by' => $by->id, 'by_name' => $by->name, 'at' => now()->toIso8601String(), 'via' => $via];
    }
}
