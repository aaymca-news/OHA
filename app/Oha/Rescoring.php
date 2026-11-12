<?php

namespace App\Oha;

use App\Models\Assessment;
use App\Models\Baseline;
use App\Models\FollowUpWindow;
use Illuminate\Support\Facades\DB;

/**
 * Where re-scoring stands during a follow-up (decided 12 Nov 2026). Follow-up N starts
 * from Baseline N (the one in force); while it is open, its answers are changed (a
 * re-uploaded Excel form, or answer by answer on the site) and kept on the follow-up's
 * window; closing it records them as Baseline N + 1. Read here the same way by the page,
 * the actions and the baseline recorded, so they never disagree.
 */
final class Rescoring
{
    /**
     * The baseline in force that follow-up $followUp starts from: Baseline $followUp (or,
     * for a follow-up closed before baselines were recorded, the newest one before it).
     */
    public static function startingPoint(Assessment $assessment, int $followUp): ?Baseline
    {
        return Baseline::query()->where('assessment_id', $assessment->id)
            ->where('number', '<=', $followUp)->whereNull('superseded_at')->orderByDesc('number')->first();
    }

    /** Baseline $number, if one is in force. */
    public static function inForce(Assessment $assessment, int $number): ?Baseline
    {
        return Baseline::query()->where('assessment_id', $assessment->id)
            ->where('number', $number)->whereNull('superseded_at')->first();
    }

    /**
     * The answers being re-scored in follow-up $followUp: as changed so far, or else the
     * starting baseline's.
     *
     * @return array<string, mixed>
     */
    public static function answers(Assessment $assessment, int $followUp, ?Baseline $start = null): array
    {
        $window = self::window($assessment, $followUp);

        return $window->answers ?? ($start ?? self::startingPoint($assessment, $followUp))->answers ?? [];
    }

    /**
     * The answers changed since the starting baseline, each with what it was there (read
     * now, from that baseline), what it is, why, who and how. Only real changes: an answer
     * set back to what it was is not one.
     *
     * @param  array<string, array<string, mixed>>|null  $recorded  the changes kept on the window
     * @param  array<string, mixed>  $start  the starting baseline's answers
     * @param  array<string, mixed>  $now  the answers being re-scored
     * @return array<string, array{from: mixed, to: mixed, reason: string, by: int|null, by_name: string, at: string, via: string, file?: string}>
     */
    public static function changes(?array $recorded, array $start, array $now): array
    {
        $changes = [];
        foreach ($recorded ?? [] as $code => $change) {
            if (self::same($start[$code] ?? null, $now[$code] ?? null)) {
                continue;
            }
            /** @var array{from: mixed, to: mixed, reason: string, by: int|null, by_name: string, at: string, via: string, file?: string} $entry */
            $entry = ['from' => $start[$code] ?? null, 'to' => $now[$code] ?? null] + $change;
            $changes[$code] = $entry;
        }

        return $changes;
    }

    /**
     * The question codes whose answer differs between two sets of answers.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function differences(array $before, array $after): array
    {
        $codes = array_unique([...array_keys($before), ...array_keys($after)]);

        return array_values(array_filter($codes, fn (string $code) => ! self::same($before[$code] ?? null, $after[$code] ?? null)));
    }

    /** Whether two answers are the same: blanks alike, figures by value, text exactly (trimmed). */
    public static function same(mixed $a, mixed $b): bool
    {
        $blank = fn (mixed $v) => $v === null || (is_string($v) && trim($v) === '');
        if ($blank($a) || $blank($b)) {
            return $blank($a) && $blank($b);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return trim((string) $a) === trim((string) $b);
    }

    /**
     * The evidence questions answered "Yes" with no evidence counted yet: in the assessment
     * (Baseline 1), or in a follow-up up to $upToFollowUp. Such a "Yes" still scores, as on
     * the form, but is flagged "no evidence yet".
     *
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function evidenceGaps(Assessment $assessment, array $answers, ?int $upToFollowUp): array
    {
        $proven = DB::table('evidence_questions as eq')
            ->join('evidence as e', 'e.id', '=', 'eq.evidence_id')
            ->where('e.assessment_id', $assessment->id)
            ->where(fn ($q) => $q->whereNull('e.counted_in')->when($upToFollowUp !== null, fn ($q) => $q->orWhere('e.counted_in', '<=', $upToFollowUp)))
            ->distinct()->pluck('eq.question_code')->all();

        return array_values(array_filter(array_keys(EvidenceQuestions::LABELS),
            fn (string $code) => Answer::isYes($answers[$code] ?? null) && ! in_array($code, $proven, true)));
    }

    /**
     * The answers that can be changed on the site, one by one: every answer the score
     * reads, but the income percentages, which are changed together.
     *
     * @return list<string>
     */
    public static function editable(): array
    {
        $codes = [];
        foreach (ScoreGaps::scored() as $items) {
            foreach ($items as $item) {
                array_push($codes, ...$item['drivers']);
            }
        }

        return array_values(array_diff(array_unique($codes), FormDefinition::INCOME));
    }

    public static function window(Assessment $assessment, int $followUp): ?FollowUpWindow
    {
        return FollowUpWindow::query()->where('assessment_id', $assessment->id)->where('number', $followUp)->first();
    }
}
