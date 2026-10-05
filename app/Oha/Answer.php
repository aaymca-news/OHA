<?php

namespace App\Oha;

/**
 * Helpers for reading answers the way the form's Excel formulas do: text is
 * compared case-insensitively, and a blank answer scores nothing.
 */
final class Answer
{
    public static function lower(mixed $value): string
    {
        return $value === null ? '' : mb_strtolower(trim((string) $value));
    }

    public static function isYes(mixed $value): bool
    {
        return self::lower($value) === 'yes';
    }

    public static function isNo(mixed $value): bool
    {
        return self::lower($value) === 'no';
    }

    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value) || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * A money figure as movements actually type it: "USD 90,000", "ZAR 418 332.92",
     * "R5 723 904,40", "ZAR - 155 673.00". The form asks for the number only, but a
     * currency code or symbol beside it does not make it unreadable. Anything else
     * (words, two numbers) is refused, so "54 branches, 30 active" stays null.
     */
    public static function amount(mixed $value): ?float
    {
        if (($plain = self::number($value)) !== null) {
            return $plain;
        }
        if (! is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/^\s*[A-Za-z]{1,3}\$?[\s.,:]*|[\s.,]*[A-Za-z]{1,3}\s*$|[$€£₵¥]/u', '', $value) ?? '');
        $text = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $text);
        if (! preg_match('/^-?\s*-?\d[\d.,]*$/', $text)) {
            return null;
        }

        $negative = str_contains($text, '-');
        $digits = ltrim($text, '- ');
        $lastComma = strrpos($digits, ',');
        $lastDot = strrpos($digits, '.');

        // With both separators, the last one is the decimal mark. With one kind used once,
        // it is the decimal mark unless exactly three digits follow ("1,234" is a thousand).
        $decimal = null;
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
        } else {
            $mark = $lastComma !== false ? ',' : ($lastDot !== false ? '.' : null);
            if ($mark !== null && substr_count($digits, $mark) === 1 && strlen($digits) - (int) strrpos($digits, $mark) - 1 !== 3) {
                $decimal = $mark;
            }
        }

        $whole = $decimal === null ? $digits : substr($digits, 0, (int) strrpos($digits, $decimal));
        $fraction = $decimal === null ? '' : substr($digits, (int) strrpos($digits, $decimal) + 1);
        $whole = str_replace([',', '.'], '', $whole);

        if ($whole === '' || ! ctype_digit($whole) || ($fraction !== '' && ! ctype_digit($fraction))) {
            return null;
        }

        $number = (float) ($whole.($fraction !== '' ? '.'.$fraction : ''));

        return $negative ? -$number : $number;
    }

    public static function has(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public static function isFederated(array $answers): bool
    {
        return self::lower($answers['Q112'] ?? null) === 'federative structure';
    }
}
