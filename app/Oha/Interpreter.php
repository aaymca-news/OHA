<?php

namespace App\Oha;

/**
 * Reads answers the way a person would. Movements often write in words what the form
 * asks for as a number or a choice: "385 volunteers", "twelve", "Y", "Yes, we do",
 * "1-5 years", "USD 1.2 million". Each is read as the number or option it plainly
 * means, and that value is what is stored and scored; what was written is kept beside it.
 *
 * Nothing ambiguous is guessed: two numbers ("54 branches, 30 active"), comparisons
 * ("more than 5") and other wording stay as written, and the check flags them.
 *
 * A figure in the wrong unit is never converted either: the form asks for months, and
 * "2 years" is flagged, not turned into 24; a money figure in a currency other than the
 * one given at Q208 is flagged; litres, kilograms and the like answer none of its questions.
 */
final class Interpreter
{
    /** What each counted (non-money, non-percentage) question measures. */
    private const MEASURES = [
        'Q103' => 'code',
        'Q114' => 'organisations', 'Q115' => 'organisations', 'Q117' => 'organisations', 'Q118' => 'organisations',
        'Q241' => 'months',
        'Q313' => 'people', 'Q314' => 'people', 'Q315' => 'people', 'Q316' => 'people', 'Q317' => 'people', 'Q318' => 'people', 'Q319' => 'people',
        'Q322' => 'years', 'Q324' => 'years', 'Q326' => 'years',
        'Q518' => 'people',
        'Q1008' => 'people', 'Q1009' => 'people', 'Q1010' => 'people', 'Q1011' => 'people', 'Q1012' => 'people', 'Q1013' => 'people',
    ];

    /** How each unit is written, by what it measures. */
    private const UNITS = [
        'days' => ['day', 'days'],
        'weeks' => ['week', 'weeks', 'wk', 'wks'],
        'months' => ['month', 'months', 'mth', 'mths', 'mo', 'mos'],
        'years' => ['year', 'years', 'yr', 'yrs'],
        'percent' => ['%', 'percent', 'per cent', 'pct'],
        'weight' => ['kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms', 'g', 'gram', 'grams', 'tonne', 'tonnes', 'ton', 'tons', 'lb', 'lbs'],
        'volume' => ['l', 'litre', 'litres', 'liter', 'liters', 'ml', 'millilitre', 'millilitres', 'gallon', 'gallons'],
        'length' => ['km', 'kms', 'kilometre', 'kilometres', 'kilometer', 'kilometers', 'm', 'metre', 'metres', 'meter', 'meters', 'cm', 'mile', 'miles'],
        'area' => ['ha', 'hectare', 'hectares', 'acre', 'acres', 'm2', 'm²', 'sqm', 'sq m', 'square metres', 'square meters'],
    ];

    private const UNIT_NAMES = [
        'days' => 'days', 'weeks' => 'weeks', 'months' => 'months', 'years' => 'years', 'percent' => 'a percentage',
        'weight' => 'a weight', 'volume' => 'a volume', 'length' => 'a distance', 'area' => 'an area', 'currency' => 'money',
    ];

    private const EXPECTED = [
        'months' => 'the number of months', 'years' => 'the number of years', 'people' => 'a number of people',
        'organisations' => 'a count', 'code' => 'a number', 'pct' => 'a percentage (25 for 25%)', 'money' => 'an amount of money',
    ];

    /** Words that only say a figure is approximate; they are dropped. */
    private const APPROXIMATELY = '/^(?:about|approximately|approx\.?|around|roughly|circa|ca\.|estimated|est\.|~|±)\s*/iu';

    /** Words that make a figure a comparison or a sum, which is never guessed at. */
    private const COMPARISON = '/\b(?:more|less|fewer|greater|over|under|above|below|least|most|up|maximum|minimum|max|min|between|and|to|or|plus)\b|[<>≤≥+\/]|\d/iu';

    private const WORDS = [
        'zero' => 0, 'none' => 0, 'nil' => 0, 'nobody' => 0, 'never' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
        'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19,
        'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
    ];

    /** Whole answers that are a number on their own. */
    private const ALONE = ['a dozen' => 12, 'dozen' => 12, 'once' => 1, 'twice' => 2, 'no one' => 0];

    private const SCALES = ['thousand' => 1_000, 'k' => 1_000, 'million' => 1_000_000, 'mn' => 1_000_000, 'm' => 1_000_000, 'billion' => 1_000_000_000, 'bn' => 1_000_000_000];

    /**
     * The form with every answer it can read put in plain form, and the answers it
     * could not accept because of their unit.
     */
    public static function apply(ReadForm $form): ReadForm
    {
        $answers = $form->answers;
        $interpreted = [];
        $wrongUnits = [];
        $currency = self::currencyCode($answers['Q208'] ?? null);

        foreach (self::questions() as $q) {
            $raw = $answers[$q->code] ?? null;
            // Text answers are kept exactly as written; numbers in a number cell need no reading.
            if ($q->type === 'text' || $raw === null || is_bool($raw) || (is_string($raw) && trim($raw) === '') || ($q->type !== 'choice' && ! is_string($raw))) {
                continue;
            }

            $result = self::read($q, $raw, $currency);
            if (isset($result['unit'])) {
                $wrongUnits[$q->code] = ['given' => (string) $raw, 'unit' => $result['unit'], 'expected' => $result['expected'] ?? self::expected($q)];
            } elseif (isset($result['value']) && $result['value'] !== $raw) {
                $answers[$q->code] = $result['value'];
                $interpreted[$q->code] = ['from' => (string) $raw, 'to' => $result['value']];
            }
        }

        return $form->withInterpretation($answers, $interpreted, $wrongUnits);
    }

    /**
     * Reads one answer for a question. Returns ['value' => …] when it is understood,
     * ['unit' => …, 'expected' => …] when it is in the wrong unit, or [] when it
     * cannot be read.
     *
     * @return array{value?: int|float|string, unit?: string, expected?: string}
     */
    public static function read(Question $q, mixed $raw, ?string $currency = null): array
    {
        if ($q->type === 'text') {
            $text = trim((string) $raw);

            return $text === '' ? [] : ['value' => $text];
        }
        if ((is_int($raw) || is_float($raw)) && $q->type !== 'choice') {
            return ['value' => $raw];
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $raw));
        if ($text === '') {
            return [];
        }

        return match ($q->type) {
            'choice' => self::choice($q, $text),
            'pct' => self::counted($text, 'pct'),
            'money' => self::money($text, $currency),
            'number' => self::counted($text, self::MEASURES[$q->code] ?? 'code'),
            default => ['value' => $text],
        };
    }

    /** What a question expects, for messages: "the number of months". */
    public static function expected(Question $q): string
    {
        return self::EXPECTED[match ($q->type) {
            'pct' => 'pct',
            'money' => 'money',
            default => self::MEASURES[$q->code] ?? 'code',
        }];
    }

    /** The question with this code, from anywhere on the form. */
    public static function question(string $code): ?Question
    {
        foreach (self::questions() as $q) {
            if ($q->code === $code) {
                return $q;
            }
        }

        return null;
    }

    /** The currency given at Q208, as its three-letter code, when it plainly is one. */
    public static function currencyCode(mixed $q208): ?string
    {
        return is_string($q208) && preg_match('/\b([A-Z]{3})\b/', $q208, $m) ? $m[1] : null;
    }

    /**
     * @return list<Question>
     */
    private static function questions(): array
    {
        $all = FormDefinition::general();
        foreach (FormDefinition::categories() as $category) {
            array_push($all, ...$category['questions']);
        }

        return $all;
    }

    /**
     * @return array{value?: string}
     */
    private static function choice(Question $q, string $text): array
    {
        $options = $q->options ?? [];
        $key = self::key($text);

        foreach ($options as $option) {
            if (self::key($option) === $key) {
                return ['value' => $option];
            }
        }

        // Yes / No / Not applicable, however written: "Y", "yes.", "Yes, we have one", "N/A".
        $yesNo = match (true) {
            (bool) preg_match('/^(?:n\/a|n\.a\.?|na|not applicable|non applicable)(?![\p{L}])/iu', $text) => 'Not Applicable',
            (bool) preg_match('/^(?:y|yes|yeah|yep|true|oui)(?![\p{L}])/iu', $text) => 'Yes',
            (bool) preg_match('/^(?:n|no|nope|false|non)(?![\p{L}])/iu', $text) => 'No',
            default => null,
        };
        if ($yesNo !== null) {
            return in_array($yesNo, $options, true) ? ['value' => $yesNo] : [];
        }

        // A number where the options count times ("None", "1 time", "2 or more times").
        if (in_array('2 or more times', $options, true)) {
            [$n, $unit] = self::split((string) preg_replace('/\btimes?\b/iu', '', $text) ?: '0');
            if ($n !== null && $unit === null) {
                return ['value' => $n <= 0 ? 'None' : ($n < 2 ? '1 time' : '2 or more times')];
            }
        }
        // A length of time where the options are ranges of years.
        if (in_array('1 - 5 years', $options, true) && ($years = self::years($text)) !== null) {
            return ['value' => $years < 1 ? 'Less than 1 year' : ($years <= 5 ? '1 - 5 years' : 'More than 5 years')];
        }

        // The start of an option, or an option followed by more words: "Board", "Single entity YMCA".
        $matches = array_values(array_filter($options, function (string $option) use ($key): bool {
            $o = self::key($option);

            return mb_strlen($key) >= 4 && (str_starts_with($o, $key) || str_starts_with($key, $o));
        }));

        return count($matches) === 1 ? ['value' => $matches[0]] : [];
    }

    /**
     * A count, a length of time or a percentage.
     *
     * @return array{value?: int|float, unit?: string, expected?: string}
     */
    private static function counted(string $text, string $measures): array
    {
        [$number, $unit] = self::split($text);
        if ($number === null) {
            return [];
        }

        $fits = match ($measures) {
            'months' => in_array($unit, [null, 'months', 'count'], true),
            'years' => in_array($unit, [null, 'years', 'count'], true),
            'pct' => in_array($unit, [null, 'percent'], true),
            default => in_array($unit, [null, 'count'], true),
        };

        return $fits ? ['value' => $number] : ['unit' => self::UNIT_NAMES[$unit] ?? (string) $unit, 'expected' => self::EXPECTED[$measures]];
    }

    /**
     * @return array{value?: int|float, unit?: string, expected?: string}
     */
    private static function money(string $text, ?string $currency): array
    {
        $given = preg_match('/^-?\s*([A-Z]{3})(?![A-Za-z])/u', $text, $m) || preg_match('/(?<![A-Za-z])([A-Z]{3})\s*$/u', $text, $m) ? $m[1] : null;
        if ($currency !== null && $given !== null && $given !== $currency) {
            return ['unit' => $given.', not '.$currency.' as given at Q208', 'expected' => 'an amount in '.$currency];
        }

        // A weight, a length of time or a percentage is not money, whatever its figure.
        [$number, $unit] = self::split($text);
        if ($number !== null && ! in_array($unit, [null, 'currency', 'count'], true)) {
            return ['unit' => self::UNIT_NAMES[$unit] ?? (string) $unit, 'expected' => self::EXPECTED['money']];
        }

        // "USD 1.2 million", "90 thousand", "R 2,5m".
        if (preg_match('/^(.*?\d[\d\s.,]*?)\s*(million|thousand|billion|mn|bn|m|k)\.?\s*(?:[A-Z]{3})?$/iu', $text, $m)
            && ($base = Answer::amount(trim($m[1]))) !== null) {
            return ['value' => self::tidy($base * self::SCALES[mb_strtolower($m[2])])];
        }

        if (($amount = Answer::amount($text)) !== null) {
            return ['value' => self::tidy($amount)];
        }

        return $number !== null && in_array($unit, [null, 'currency'], true) ? ['value' => $number] : [];
    }

    /**
     * One plain figure and the kind of unit written after it: null when there is none,
     * "count" for any other noun ("385 volunteers"). [null, null] when the answer is not
     * one plain figure.
     *
     * @return array{0: int|float|null, 1: string|null}
     */
    private static function split(string $text): array
    {
        $text = trim((string) preg_replace(self::APPROXIMATELY, '', trim($text)));
        $text = trim((string) preg_replace('/[.;:!,]+$/u', '', $text));

        // A currency before or after the figure: money.
        $currency = false;
        if (preg_match('/^(?:[A-Z]{3}(?![A-Za-z])|[$€£₵¥₦])\s*(.*)$/u', $text, $m)) {
            [$currency, $text] = [true, trim($m[1])];
        } elseif (preg_match('/^(.*?)\s*(?<![A-Za-z])[A-Z]{3}$/u', $text, $m) && preg_match('/\d/', $m[1])) {
            [$currency, $text] = [true, trim($m[1])];
        }
        if ($text === '') {
            return [null, null];
        }

        if (preg_match('/^(-?\d(?:[\d\s,.]*\d)?)\s*(.*)$/u', $text, $m)) {
            $number = Answer::amount($m[1]);
            $words = mb_strtolower(trim($m[2]));
        } else {
            $lower = mb_strtolower($text);
            [$number, $words] = [self::spelled($lower), ''];
            if ($number === null && str_contains($lower, ' ')) {
                $parts = explode(' ', $lower);
                $words = (string) array_pop($parts);
                $number = self::spelled(implode(' ', $parts));
            }
        }
        if ($number === null) {
            return [null, null];
        }
        if ($words !== '' && $words !== '%' && preg_match(self::COMPARISON, $words)) {
            return [null, null];
        }

        $unit = $currency ? 'currency' : ($words === '' ? null : (self::unitOf($words) ?? 'count'));

        return [self::tidy($number), $unit];
    }

    /** The kind of unit written, or null if it is not a unit (it is then what is counted). */
    private static function unitOf(string $words): ?string
    {
        $words = trim($words, ' .');
        $first = explode(' ', $words)[0];
        foreach (self::UNITS as $kind => $spellings) {
            if (in_array($words, $spellings, true) || in_array($first, $spellings, true)) {
                return $kind;
            }
        }

        return null;
    }

    /** A number written in words: "twelve", "twenty-five", "one hundred and five", "none". */
    private static function spelled(string $text): ?int
    {
        $text = trim((string) preg_replace('/[\s-]+/u', ' ', mb_strtolower($text)));
        if (isset(self::ALONE[$text])) {
            return self::ALONE[$text];
        }

        $total = 0;
        $current = 0;
        $seen = false;
        foreach (explode(' ', $text) as $word) {
            if ($word === 'and' && $seen) {
                continue;
            }
            if (isset(self::WORDS[$word])) {
                $current += self::WORDS[$word];
                $seen = true;
            } elseif ($word === 'hundred' && $seen) {
                $current *= 100;
            } elseif (($word === 'thousand' || $word === 'million') && $seen) {
                $total += $current * self::SCALES[$word];
                $current = 0;
            } else {
                return null;
            }
        }

        // Every word was a number word (an unknown one returns null above), so one was seen.
        return $total + $current;
    }

    /** A length of time in years: "3 years", "18 months", "six". */
    private static function years(string $text): ?float
    {
        [$number, $unit] = self::split($text);

        return $number === null ? null : match ($unit) {
            null, 'years' => (float) $number,
            'months' => $number / 12,
            'weeks' => $number / 52,
            'days' => $number / 365,
            default => null,
        };
    }

    private static function tidy(int|float $number): int|float
    {
        return is_float($number) && floor($number) === $number && abs($number) < PHP_INT_MAX ? (int) $number : $number;
    }

    private static function key(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($text));
    }
}
