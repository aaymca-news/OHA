<?php

namespace App\Oha;

use Closure;

/**
 * The official "YMCA Annual Organisational Health Assessment Form, Version 1.0 – 2026".
 *
 * A Welcome sheet, "1 General Information", one sheet per category ("2 Financial
 * Stability" … "10 Staff+Volunteer Development") and a Scores sheet. Each question
 * row carries a code (Q101…Q1013), the answer, an Excel scoring formula and an
 * "Additional Information" column. Each question's points closure mirrors that
 * cell's formula; a blank answer scores 0, exactly as SUM() treats the form's "".
 *
 * This describes the form, not the weights: how many points a category is worth
 * lives in the categories table. If the form is revised, only this class changes.
 */
final class FormDefinition
{
    public const VERSION = 'Version 1.0 - 2026';

    /** Answers that are really the form's own prompts, and so read as blank. */
    public const PLACEHOLDERS = ['/^please choose an option$/i', '/^please write your answer here$/i'];

    public const INCOME = ['Q214', 'Q215', 'Q216', 'Q217', 'Q218', 'Q219', 'Q220', 'Q221', 'Q222'];

    /** Questions that hold a money figure, in the currency given at Q208. */
    public const MONEY = ['Q209', 'Q210', 'Q211', 'Q212', 'Q213', 'Q240', 'Q243', 'Q246', 'Q248'];

    public const EXPENSE = ['Q229', 'Q230', 'Q231', 'Q232', 'Q233', 'Q234', 'Q235', 'Q236', 'Q237'];

    /** "1 General Information": code in C, question in D, answer in E, notes in F. */
    public const GENERAL_SHEET = 1;

    public const GENERAL_COLUMNS = ['code' => 'C', 'text' => 'D', 'answer' => 'E', 'info' => 'F'];

    /** Category sheets: code in B, question in C, answer in D, score in E, notes in F. */
    public const CATEGORY_COLUMNS = ['code' => 'B', 'text' => 'C', 'answer' => 'D', 'info' => 'F'];

    /**
     * The sign-off block on "1 General Information". The Welcome sheet says the form
     * is final only once the CEO/General Secretary and the President/Chair agree it.
     *
     * @var list<array{match: string, label: string, mustBeYes: bool}>
     */
    public const SIGNOFF = [
        ['match' => '/^chair|president/i', 'label' => 'Chair / President of the Board', 'mustBeYes' => true],
        ['match' => '/^n?gs\b|general secretary|ceo/i', 'label' => 'National General Secretary (NGS)', 'mustBeYes' => true],
        ['match' => '/^key staff/i', 'label' => 'Key Staff', 'mustBeYes' => false],
        ['match' => '/^national board/i', 'label' => 'National Board', 'mustBeYes' => false],
    ];

    private const YN = ['Yes', 'No'];

    private const YNNA = ['Yes', 'No', 'Not Applicable'];

    /**
     * @return list<Question>
     */
    public static function general(): array
    {
        $federated = self::when(fn ($a) => Answer::isFederated($a), 'Required for a federated structure (Q112).');

        return [
            self::text('Q101'), self::text('Q102'), self::number('Q103', required: false), self::text('Q104'), self::text('Q105'),
            self::yesNo('Q106'), self::text('Q107'),
            self::choice('Q108', self::YNNA), self::choice('Q109', self::YNNA), self::choice('Q110', self::YNNA), self::text('Q111', required: false),
            self::choice('Q112', ['Federative Structure', 'Single Entity', 'Other']),
            self::number('Q114', required: $federated),
            self::number('Q115', required: false),
            self::text('Q116', required: false),
            self::number('Q117', required: $federated),
            self::number('Q118', required: self::when(fn ($a) => Answer::lower($a['Q112'] ?? null) === 'single entity', 'Required for a single entity (Q112).')),
        ];
    }

    /**
     * Category code => the sheet number it is on and its questions.
     *
     * @return array<string, array{sheet: int, questions: list<Question>}>
     */
    public static function categories(): array
    {
        return [
            'financial' => ['sheet' => 2, 'questions' => self::financial()],
            'governance' => ['sheet' => 3, 'questions' => self::governance()],
            'constitution' => ['sheet' => 4, 'questions' => self::constitution()],
            'me' => ['sheet' => 5, 'questions' => self::monitoring()],
            'strategy' => ['sheet' => 6, 'questions' => self::strategy()],
            'diversity' => ['sheet' => 7, 'questions' => self::diversity()],
            'comms' => ['sheet' => 8, 'questions' => self::communications()],
            'property' => ['sheet' => 9, 'questions' => self::property()],
            'staff' => ['sheet' => 10, 'questions' => self::staff()],
        ];
    }

    /**
     * Highest score the form's own formulas can award in each category.
     *
     * @return array<string, int>
     */
    public static function attainablePoints(): array
    {
        $attainable = [];

        foreach (self::categories() as $code => $category) {
            $attainable[$code] = array_sum(array_map(fn (Question $q) => $q->max ?? 0, $category['questions']));
        }

        return $attainable;
    }

    /**
     * @return list<Question>
     */
    private static function financial(): array
    {
        $ifYes = fn (string $code, string $reason) => self::when(fn ($a) => Answer::isYes($a[$code] ?? null), $reason);
        $reserve = $ifYes('Q239', 'Required because Q239 (cash reserve) is "Yes".');
        $debt = $ifYes('Q242', 'Required because Q242 (outstanding debt) is "Yes".');

        return [
            self::point('Q201'), self::point('Q202'), self::pointOrNa('Q203'), self::pointOrNa('Q204'), self::pointOrNa('Q205'),
            self::choice('Q206', ['Not Applicable', 'Less than 1 year', '1 - 5 years', 'More than 5 years'],
                max: 1, points: fn ($a) => Answer::lower($a['Q206'] ?? null) === '1 - 5 years' ? 1 : 0),
            self::point('Q207'),
            self::text('Q208'), self::money('Q209'), self::money('Q210'), self::money('Q211'), self::money('Q212'), self::money('Q213', required: false),
            // Income mix: the largest single source above 65% scores 0, 50–65% scores 1, otherwise 2.
            self::percent('Q214', max: 2, group: 'income', points: function ($a) {
                $shares = array_filter(array_map(fn ($code) => Answer::number($a[$code] ?? null), self::INCOME), fn ($v) => $v !== null);
                if ($shares === []) {
                    return 0;
                }
                $top = max($shares);

                return $top > 65 ? 0 : ($top >= 50 ? 1 : 2);
            }),
            ...array_map(fn ($code) => self::percent($code, group: 'income'), array_slice(self::INCOME, 1)),
            // International share above 75% scores 0. The formula sits on Q223's row but reads Q224.
            self::percent('Q223', required: true, max: 1, points: fn ($a) => ($v = Answer::number($a['Q224'] ?? null)) === null ? 0 : ($v > 75 ? 0 : 1)),
            self::percent('Q224', required: true),
            self::percent('Q225', required: true, max: 1, points: fn ($a) => ($v = Answer::number($a['Q225'] ?? null)) === null ? 0 : ($v < 85 ? 1 : 0)),
            self::percent('Q226', required: true),
            self::pointForNo('Q227'), self::point('Q228'),
            ...array_map(fn ($code) => self::percent($code, group: 'expense'), self::EXPENSE),
            self::yesNo('Q238', max: 3, points: fn ($a) => Answer::isYes($a['Q238'] ?? null) ? 3 : 0),
            self::point('Q239'),
            self::money('Q240', required: $reserve),
            self::number('Q241', required: $reserve, max: 1, points: fn ($a) => (Answer::number($a['Q241'] ?? null) ?? 0) > 6 ? 1 : 0),
            self::pointForNo('Q242'),
            self::money('Q243', required: $debt),
            // One point only when BOTH debt-risk questions are answered "No".
            new Question('Q244', 'choice', self::YNNA, $debt, 1, fn ($a) => Answer::isNo($a['Q244'] ?? null) && Answer::isNo($a['Q245'] ?? null) ? 1 : 0),
            new Question('Q245', 'choice', self::YNNA, $debt),
            self::money('Q246'),
            self::yesNo('Q247'),
            self::money('Q248', required: $ifYes('Q247', 'Required because Q247 (support to other YMCAs) is "Yes".')),
        ];
    }

    /**
     * @return list<Question>
     */
    private static function governance(): array
    {
        $ifYes = fn (string $code, string $reason) => self::when(fn ($a) => Answer::isYes($a[$code] ?? null), $reason);

        return [
            self::choice('Q310', ['General Assembly', 'Board of Directors', 'Other']),
            self::text('Q311', required: self::when(fn ($a) => Answer::lower($a['Q310'] ?? null) === 'other', 'Required because Q310 is "Other".')),
            self::text('Q312'), self::number('Q313'), self::number('Q314'), self::number('Q315'), self::number('Q316'),
            self::number('Q317', required: false), self::number('Q318'), self::number('Q319'),
            self::choice('Q320', self::YNNA, max: 1, points: fn ($a) => Answer::isYes($a['Q320'] ?? null) ? 1 : 0),
            self::text('Q321', required: $ifYes('Q320', 'Required because Q320 (skills-based recruitment) is "Yes".')),
            self::number('Q322', max: 1, points: fn ($a) => ($v = Answer::number($a['Q322'] ?? null)) === null ? 0 : ($v >= 5 ? 0 : 1)),
            self::choice('Q323', self::YNNA, max: 1, points: fn ($a) => Answer::isYes($a['Q323'] ?? null) ? 1 : 0),
            self::number('Q324', required: $ifYes('Q323', 'Required because Q323 (board term limit) is "Yes".')),
            // The form reuses Q325/Q326 further down; the second use is read as "#2".
            self::choice('Q325', self::YNNA, max: 1, points: fn ($a) => Answer::isYes($a['Q325'] ?? null) ? 1 : 0),
            self::number('Q326', required: $ifYes('Q325', 'Required because Q325 (President term limit) is "Yes".')),
            self::point('Q325#2'),
            self::choice('Q326#2', ['None', '1 time', '2 or more times'], max: 1, points: fn ($a) => Answer::lower($a['Q326#2'] ?? null) === '2 or more times' ? 1 : 0),
            self::point('Q327'), self::point('Q328'),
            self::yesNo('Q329'),
            self::point('Q330'), self::point('Q331'), self::point('Q332'), self::point('Q333'),
        ];
    }

    /**
     * @return list<Question>
     */
    private static function constitution(): array
    {
        return [
            self::point('Q410'), self::yesNo('Q411'),
            ...array_map(fn ($code) => self::point($code), ['Q412', 'Q413', 'Q414', 'Q415', 'Q416', 'Q417', 'Q418', 'Q419', 'Q420', 'Q421', 'Q422', 'Q423']),
            // Worth a point on any structure (E17 is inside the official total E2:E23), but only
            // a federation must answer it, so a single entity can reach 19 of 20.
            self::yesNo('Q424', required: self::when(fn ($a) => Answer::isFederated($a), 'Required for a federated structure (Q112).'),
                max: 1, points: fn ($a) => Answer::isYes($a['Q424'] ?? null) ? 1 : 0),
            ...array_map(fn ($code) => self::point($code), ['Q425', 'Q426', 'Q427', 'Q428', 'Q429', 'Q430']),
            self::text('Q431', required: false),
        ];
    }

    /**
     * @return list<Question>
     */
    private static function monitoring(): array
    {
        return [
            self::point('Q511'), self::point('Q512'), self::point('Q513'), self::point('Q514'), self::point('Q515'), self::point('Q516'),
            self::text('Q517', required: self::when(fn ($a) => Answer::isYes($a['Q516'] ?? null), 'Required because Q516 (impact measurement tools) is "Yes".')),
            self::number('Q518'),
        ];
    }

    /**
     * @return list<Question>
     */
    private static function strategy(): array
    {
        $vision = self::when(fn ($a) => Answer::isYes($a['Q620'] ?? null), 'Required because Q620 (Vision 2030) is "Yes".');

        return [
            self::yesNo('Q610', max: 3, points: fn ($a) => Answer::isYes($a['Q610'] ?? null) ? 3 : 0),
            self::text('Q611', required: self::when(fn ($a) => Answer::isYes($a['Q610'] ?? null), 'Required because Q610 (Strategic Plan) is "Yes".')),
            self::point('Q612'), self::point('Q613'), self::point('Q614'), self::point('Q615'), self::point('Q616'),
            self::text('Q617', required: false),
            self::point('Q618'), self::point('Q619'), self::point('Q620'),
            ...array_map(fn ($code) => self::yesNo($code, required: $vision), ['Q621', 'Q622', 'Q623', 'Q624', 'Q625', 'Q626', 'Q627']),
            self::text('Q628'), self::text('Q629'),
            self::point('Q630'),
            self::yesNo('Q631'), self::yesNo('Q632'), self::yesNo('Q633'), self::yesNo('Q634'), self::yesNo('Q635'),
        ];
    }

    /**
     * @return list<Question>
     */
    private static function diversity(): array
    {
        return [
            // Q710 has a formula on the form, but the category total (E4:E9) leaves it out.
            self::yesNo('Q710'),
            self::point('Q711'), self::point('Q712'), self::yesNo('Q713'),
            self::point('Q714'),
            self::text('Q715', required: self::when(fn ($a) => Answer::isYes($a['Q714'] ?? null), 'Required because Q714 is "Yes".')),
            self::point('Q716'),
            self::text('Q717', required: self::when(fn ($a) => Answer::isYes($a['Q716'] ?? null), 'Required because Q716 is "Yes".')),
        ];
    }

    /**
     * @return list<Question>
     */
    private static function communications(): array
    {
        return [
            self::point('Q810'), self::point('Q811'), self::point('Q812'), self::point('Q813'), self::point('Q814'),
            // The form gives Q815 no dropdown, so any answer is accepted.
            self::text('Q815', required: self::when(fn ($a) => Answer::isFederated($a), 'Required for a federated structure (Q112).')),
        ];
    }

    /**
     * @return list<Question>
     */
    private static function property(): array
    {
        $owns = self::when(fn ($a) => Answer::isYes($a['Q910'] ?? null), 'Required because Q910 (owns property) is "Yes".');

        return [
            self::yesNo('Q910'),
            self::text('Q911', required: $owns),
            self::yesNo('Q912', required: $owns),
            self::yesNo('Q913'),
            self::text('Q914', required: self::when(fn ($a) => Answer::isYes($a['Q913'] ?? null), 'Required because Q913 (risk of loss) is "Yes".'), notesFrom: 'Q913'),
            self::yesNo('Q915', required: $owns),
        ];
    }

    /**
     * @return list<Question>
     */
    private static function staff(): array
    {
        return [
            self::point('Q1001'), self::point('Q1002'), self::point('Q1003'), self::point('Q1004'),
            self::text('Q1005', required: false), self::text('Q1006', required: false),
            self::point('Q1007'),
            self::number('Q1008'), self::number('Q1009'), self::number('Q1010'), self::number('Q1011'), self::number('Q1012'), self::number('Q1013'),
        ];
    }

    /* ---- question builders ------------------------------------------------ */

    /**
     * A closure that says why a question is required when the test holds.
     *
     * @param  Closure(array<string, mixed>): bool  $test
     * @return Closure(array<string, mixed>): (string|false)
     */
    private static function when(Closure $test, string $reason): Closure
    {
        return fn (array $answers) => $test($answers) ? $reason : false;
    }

    /**
     * @param  bool|Closure(array<string, mixed>): (string|false)  $required
     * @param  (Closure(array<string, mixed>): int)|null  $points
     */
    private static function yesNo(string $code, bool|Closure $required = true, ?int $max = null, ?Closure $points = null): Question
    {
        return new Question($code, 'choice', self::YN, $required, $max, $points);
    }

    /** A Yes/No question where "Yes" earns one point. */
    private static function point(string $code): Question
    {
        return self::yesNo($code, max: 1, points: fn ($a) => Answer::isYes($a[$code] ?? null) ? 1 : 0);
    }

    /** A Yes/No/Not Applicable question where "Yes" earns one point. */
    private static function pointOrNa(string $code): Question
    {
        return new Question($code, 'choice', self::YNNA, true, 1, fn ($a) => Answer::isYes($a[$code] ?? null) ? 1 : 0);
    }

    /** A Yes/No question where "No" earns one point. */
    private static function pointForNo(string $code): Question
    {
        return self::yesNo($code, max: 1, points: fn ($a) => Answer::isNo($a[$code] ?? null) ? 1 : 0);
    }

    /**
     * @param  list<string>  $options
     * @param  (Closure(array<string, mixed>): int)|null  $points
     */
    private static function choice(string $code, array $options, ?int $max = null, ?Closure $points = null): Question
    {
        return new Question($code, 'choice', $options, true, $max, $points);
    }

    /**
     * @param  bool|Closure(array<string, mixed>): (string|false)  $required
     * @param  (Closure(array<string, mixed>): int)|null  $points
     */
    private static function number(string $code, bool|Closure $required = true, ?int $max = null, ?Closure $points = null): Question
    {
        return new Question($code, 'number', null, $required, $max, $points);
    }

    /**
     * A money figure. Movements often type a currency beside it ("USD 90,000"); that
     * still reads as a number (see Answer::amount).
     *
     * @param  bool|Closure(array<string, mixed>): (string|false)  $required
     */
    private static function money(string $code, bool|Closure $required = true): Question
    {
        return new Question($code, 'money', null, $required);
    }

    /**
     * @param  (Closure(array<string, mixed>): int)|null  $points
     */
    private static function percent(string $code, bool $required = false, ?int $max = null, ?string $group = null, ?Closure $points = null): Question
    {
        return new Question($code, 'pct', null, $required, $max, $points, $group);
    }

    /**
     * @param  bool|Closure(array<string, mixed>): (string|false)  $required
     */
    private static function text(string $code, bool|Closure $required = true, ?string $notesFrom = null): Question
    {
        return new Question($code, 'text', null, $required, notesFrom: $notesFrom);
    }
}
