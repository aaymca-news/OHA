<?php

namespace App\Oha;

use App\Exceptions\WorkflowRuleBroken;

/**
 * An answer typed in the platform, read as its question asks for it: one of its options,
 * a figure, a percentage, or the percentages of a group. Read the same way as an answer
 * in the Excel form (see Interpreter), so a typed answer scores exactly as the form would
 * score it. Refused with a plain reason otherwise. Used for the answers typed for a form's
 * gaps (SupplyAnswer) and for the answers changed at a follow-up (Rescore).
 */
final class TypedAnswer
{
    /** One answer; $currency is the form's currency code (Q208), for money figures. */
    public static function one(Question $q, string $input, ?string $currency = null): int|float|string
    {
        $text = trim($input);
        if ($text === '') {
            throw new WorkflowRuleBroken($q->type === 'choice' ? 'Choose an answer.' : 'Type the answer.');
        }

        $read = Interpreter::read($q, $text, $currency);
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
    public static function shares(string $group, string|array $input): array
    {
        $codes = self::group($group);
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
     * The question codes of a group of percentages.
     *
     * @return list<string>
     */
    public static function group(string $group): array
    {
        return match ($group) {
            'income' => FormDefinition::INCOME,
            'expense' => FormDefinition::EXPENSE,
            default => throw new WorkflowRuleBroken('This group is not on the form.'),
        };
    }
}
