<?php

namespace App\Oha;

use App\Models\FormFinding;

/**
 * What can be typed to settle a finding of the OHA form check, and how:
 *
 *   answers   one or more answers ("Q246"; the staff head count Q1008–Q1011 when it
 *             does not add up; the two shares of local and international funding)
 *   shares    a group of percentages that must make 100 (income by source, expenditure)
 *   comment   the areas of improvement for a category
 *   signoff   whether someone took part in completing the form (Yes or No)
 *
 * Null when nothing typed can settle it (a missing sheet, a formula overwritten in the
 * file, a different version of the form): it is then marked as reviewed with a note.
 */
final class FindingFields
{
    private const STAFF = ['Q1008', 'Q1009', 'Q1010', 'Q1011'];

    private const BOARD = ['Q313', 'Q314', 'Q315', 'Q316', 'Q317'];

    /**
     * @return array{kind: string, key: string, codes: list<string>}|null
     */
    public static function for(FormFinding $finding): ?array
    {
        [$kind, $key] = $finding->ref !== null ? explode(':', $finding->ref, 2) + [1 => ''] : [null, ''];
        $code = $finding->question_code;
        $message = $finding->message;

        return match (true) {
            $kind === 'q' => self::answers($key),
            $kind === 'g' => self::shares($key),
            $kind === 'c' => ['kind' => 'comment', 'key' => $key, 'codes' => []],
            $kind === 's' => ['kind' => 'signoff', 'key' => $key, 'codes' => []],
            $finding->rule === 'sum_mismatch' && str_starts_with($message, 'Q214') => self::shares('income'),
            $finding->rule === 'sum_mismatch' && str_starts_with($message, 'Q229') => self::shares('expense'),
            $finding->rule === 'sum_mismatch' && str_starts_with($message, 'Q223') => self::answers('Q223', 'Q224'),
            $finding->rule === 'sum_mismatch' && str_starts_with($message, 'Q225') => self::answers('Q225', 'Q226'),
            $finding->rule === 'inconsistent' && $code === 'Q213' => self::answers('Q211', 'Q212', 'Q213'),
            $finding->rule === 'inconsistent' && str_starts_with($message, 'Permanent') => self::answers(...self::STAFF),
            $finding->rule === 'inconsistent' && str_starts_with($message, 'The board has') => self::answers(...self::BOARD),
            $finding->rule === 'inconsistent' && in_array($code, ['Q318', 'Q319'], true) => self::answers($code, ...array_slice(self::BOARD, 2)),
            $finding->rule === 'inconsistent' && $code === 'Q1012' => self::answers('Q1012', 'Q1008'),
            $finding->rule === 'scoring_note' && $code === 'Q244' => self::answers('Q244', 'Q245'),
            in_array($finding->rule, ['non_numeric', 'wrong_unit', 'invalid_option'], true) && $code !== null => self::answers($code),
            default => null,
        };
    }

    /**
     * What a question expects, as a guide beside the box: the kind of answer, an example,
     * and the unit.
     *
     * @return array{type: string, example: string}
     */
    public static function guide(Question $q, ?string $currency = null): array
    {
        $expected = Interpreter::expected($q);

        return match (true) {
            $q->type === 'choice' && $q->options === ['Yes', 'No'] => ['type' => 'Yes or No', 'example' => 'Choose one.'],
            $q->type === 'choice' => ['type' => 'One of the form’s options', 'example' => implode(' · ', $q->options ?? [])],
            $q->type === 'pct' => ['type' => 'A percentage, 0 to 100', 'example' => 'Type 25 for 25%. Not money, not a count.'],
            $q->type === 'money' => ['type' => 'An amount of money'.($currency ? ' in '.$currency : ''), 'example' => 'Type the figure only, for example 10005. Another currency is refused.'],
            str_contains($expected, 'months') => ['type' => 'A number of months', 'example' => 'For example 8. Years or weeks are refused: convert to months first.'],
            str_contains($expected, 'years') => ['type' => 'A number of years', 'example' => 'For example 4. Months are refused: convert to years first.'],
            str_contains($expected, 'people') => ['type' => 'A whole number of people', 'example' => 'For example 12, or “twelve”. Not a percentage or money.'],
            $q->type === 'number' => ['type' => 'A whole number', 'example' => 'For example 54. One figure only.'],
            default => ['type' => 'Text', 'example' => 'As the movement gave it.'],
        };
    }

    /**
     * @return array{kind: string, key: string, codes: list<string>}
     */
    private static function answers(string ...$codes): array
    {
        return ['kind' => 'answers', 'key' => $codes[0], 'codes' => array_values($codes)];
    }

    /**
     * @return array{kind: string, key: string, codes: list<string>}|null
     */
    private static function shares(string $group): ?array
    {
        return match ($group) {
            'income' => ['kind' => 'shares', 'key' => 'income', 'codes' => FormDefinition::INCOME],
            'expense' => ['kind' => 'shares', 'key' => 'expense', 'codes' => FormDefinition::EXPENSE],
            default => null,
        };
    }
}
