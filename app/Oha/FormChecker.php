<?php

namespace App\Oha;

use App\Enums\FindingSeverity;
use App\Models\Category;
use App\Models\Movement;
use Normalizer;

/**
 * Judges a read OHA form: is it the right form for the right movement, what does
 * it score, and exactly what is missing.
 *
 * Three severities:
 *   error    refuses the form: not the OHA form, the wrong movement, or nothing answered.
 *   missing  a gap. The assessor may proceed with gaps; each stays on the record until resolved.
 *   warning  worth a look (figures that do not add up, answers outside the list). Never blocks.
 */
final class FormChecker
{
    /** @var list<Finding> */
    private array $findings = [];

    /** @var array<string, array{name: string, weighted: bool}> */
    private array $categories = [];

    public function __construct(private readonly Scorer $scorer) {}

    public function check(ReadForm $form, ?Movement $expected = null): CheckResult
    {
        $this->findings = [];
        $this->categories = Category::query()->get()
            ->mapWithKeys(fn (Category $c) => [$c->code => ['name' => $c->name, 'weighted' => $c->max_points !== null]])
            ->all();

        if (! $this->isTheOhaForm($form)) {
            return $this->refused();
        }

        $answers = $form->answers;
        $missingSheets = $form->missingCategorySheets();

        foreach ($missingSheets as $code) {
            $this->add(FindingSeverity::Missing, FindingRule::MissingSection,
                'The '.$this->name($code).' sheet is missing from the workbook.',
                location: 'Sheet '.FormDefinition::categories()[$code]['sheet'],
                hint: 'The whole section is unanswered and scores 0. Ask the movement for the complete form.',
                ref: 'sheet:'.$code, category: $code);
        }

        if ($form->version !== null && $form->version !== '' && $form->version !== FormDefinition::VERSION) {
            $this->add(FindingSeverity::Warning, FindingRule::FormVersion,
                'The workbook says "'.$form->version.'"; this system reads '.FormDefinition::VERSION.'.',
                location: $form->generalSheet.' · A2',
                hint: 'Questions may have moved or changed. Check the scores against the form before submitting.');
        }

        if ($expected !== null) {
            $this->checkMovement($form, $expected);
        }

        $stake = $this->checkQuestions($form, $missingSheets);

        if ($form->categorySheets['financial'] !== null) {
            $stake['financial'] = ($stake['financial'] ?? 0) + $this->checkFinancialFigures($form);
        }

        $this->checkConsistency($form);
        $this->checkOverwrittenScoring($form);
        $this->checkComments($form);
        $this->checkSignoff($form);

        $answered = count(array_filter($answers, fn ($v) => Answer::has($v)));
        if ($answered < 10) {
            $this->add(FindingSeverity::Error, FindingRule::EmptyForm,
                'The form has almost nothing filled in ('.$answered.' answers).',
                location: 'Workbook', hint: 'This looks like the blank form. Upload the movement’s completed copy.');
        }

        $points = $this->scorer->score($answers, $missingSheets);
        $this->checkPrintedTotals($form, $points);

        if ($this->withSeverity(FindingSeverity::Error) !== []) {
            return $this->refused();
        }

        $weightedStake = array_filter($stake, fn ($_, $code) => $this->categories[$code]['weighted'] ?? false, ARRAY_FILTER_USE_BOTH);

        return new CheckResult(true, $this->findings, $points, $this->cover($form), $weightedStake);
    }

    private function isTheOhaForm(ReadForm $form): bool
    {
        if ($form->generalSheet !== null && count($form->missingCategorySheets()) <= 4) {
            return true;
        }

        $this->add(FindingSeverity::Error, FindingRule::MissingSheet,
            'This is not the Organisational Health Assessment form.',
            location: 'Workbook',
            hint: 'Expected sheets "1 General Information" to "10 Staff+Volunteer Development". Found: '
                .implode(', ', $form->sheetNames).'. Download the blank OHA form and send that to the movement.');

        return false;
    }

    /**
     * Exact on the country (Q102), ignoring spacing, accents and hyphens: a loose
     * "contains" would let a Guinea-Bissau form through against Guinea. Country
     * plus capital ("Guinea Conakry") is accepted too. Only when Q102 is blank does
     * it fall back to the legal name (Q101).
     */
    private function checkMovement(ReadForm $form, Movement $expected): void
    {
        $normalise = fn (mixed $v) => (string) preg_replace('/[^a-z]/', '', (string) Normalizer::normalize(Answer::lower($v), Normalizer::FORM_D));

        $want = $normalise($expected->country);
        $accepted = [$want, $want.$normalise($expected->city)];
        $country = $form->answers['Q102'] ?? null;
        $legalName = $form->answers['Q101'] ?? null;

        $mismatch = Answer::has($country)
            ? ! in_array($normalise($country), $accepted, true)
            : Answer::has($legalName) && ! str_contains($normalise($legalName), $want);

        if ($want !== '' && $mismatch) {
            $this->add(FindingSeverity::Error, FindingRule::MovementMismatch,
                'The form is for "'.($country ?? $legalName).'" but you are uploading it against '.$expected->name.'.',
                location: $form->generalSheet.' · Q102 Country',
                hint: 'Uploading a form to the wrong movement would corrupt that movement’s record. Check the file.');
        }
    }

    /**
     * Unanswered questions (including follow-ups the answers make necessary), and
     * answers that are present but invalid. Returns points left unanswered per category.
     *
     * @param  list<string>  $missingSheets
     * @return array<string, int>
     */
    private function checkQuestions(ReadForm $form, array $missingSheets): array
    {
        $answers = $form->answers;
        $bank = ['general' => FormDefinition::general()]
            + array_map(fn ($c) => $c['questions'], FormDefinition::categories());
        $stake = [];

        foreach ($bank as $category => $questions) {
            if ($category !== 'general' && in_array($category, $missingSheets, true)) {
                continue;
            }

            foreach ($questions as $q) {
                $answer = $answers[$q->code] ?? null;
                $where = $form->locations[$q->code] ?? null;
                $location = ($where['sheet'] ?? $form->categorySheets[$category] ?? $form->generalSheet).' · '.$q->displayCode();

                // Grouped percentages are judged as a group, in checkFinancialFigures().
                if ($q->group !== null) {
                    continue;
                }

                $reason = $q->requiredBecause($answers);
                if ($reason !== false && $q->notesFrom !== null && mb_strlen((string) ($form->notes[$q->notesFrom] ?? '')) >= 10) {
                    $reason = false;
                }

                if ($reason !== false && ! Answer::has($answer)) {
                    if ($category !== 'general') {
                        $stake[$category] = ($stake[$category] ?? 0) + ($q->max ?? 0);
                    }
                    $this->add(FindingSeverity::Missing,
                        $q->required === true ? FindingRule::Unanswered : FindingRule::ConditionalMissing,
                        str_replace('#2', '', $q->code).' — '.($where['text'] ?? 'question not found on the sheet'),
                        location: $location,
                        hint: ($q->required === true ? 'Not answered.' : $reason)
                            .($q->max ? ' Worth up to '.$q->max.' point'.($q->max === 1 ? '' : 's').'; scores 0 until answered.' : ''),
                        ref: 'q:'.$q->code, question: $q->code, category: $category, pointsAtStake: $q->max ?? 0);
                }

                if (Answer::has($answer) && $q->type === 'choice' && $q->options !== null
                    && ! in_array(Answer::lower($answer), array_map(fn ($o) => Answer::lower($o), $q->options), true)) {
                    $this->add(FindingSeverity::Warning, FindingRule::InvalidOption,
                        $q->code.' holds "'.$this->clip((string) $answer, 40).'", which is not one of the form’s options.',
                        location: $location,
                        hint: 'Allowed: '.implode(', ', $q->options).'.'.($q->points !== null ? ' It scores 0 as entered.' : ''),
                        question: $q->code, category: $category);
                }

                // A money figure may carry its currency ("USD 90,000"); only text that is not an amount is flagged.
                $readable = $q->type === 'money' ? Answer::amount($answer) : Answer::number($answer);
                if (Answer::has($answer) && in_array($q->type, ['number', 'pct', 'money'], true) && $readable === null) {
                    $this->add(FindingSeverity::Warning, FindingRule::NonNumeric,
                        $q->code.' holds "'.$this->clip((string) $answer, 40).'" where a number was expected.',
                        location: $location, hint: 'Enter the number only, without currency or units.',
                        question: $q->code, category: $category);
                }

                $number = Answer::number($answer);
                if ($q->type === 'pct' && $number !== null && ($number < 0 || $number > 100)) {
                    $this->add(FindingSeverity::Warning, FindingRule::OutOfRange,
                        $q->code.' is '.$this->figure($number).'%, outside 0–100.',
                        location: $location, hint: 'Percentages are entered as whole numbers, e.g. 25 for 25%.',
                        question: $q->code, category: $category);
                }
            }
        }

        return $stake;
    }

    /**
     * Percentage groups must be filled in and add up to about 100. Returns the
     * points at stake when the income mix is left blank.
     */
    private function checkFinancialFigures(ReadForm $form): int
    {
        $answers = $form->answers;
        $stake = 0;

        $groups = [
            ['keys' => FormDefinition::INCOME, 'label' => 'Q214–Q222 Income by source', 'ref' => 'g:income', 'points' => 2],
            ['keys' => FormDefinition::EXPENSE, 'label' => 'Q229–Q237 Expenditure by type', 'ref' => 'g:expense', 'points' => 0],
        ];

        foreach ($groups as $group) {
            $filled = array_filter(array_map(fn ($k) => Answer::number($answers[$k] ?? null), $group['keys']), fn ($v) => $v !== null);
            $first = $group['keys'][0];
            $location = ($form->locations[$first]['sheet'] ?? '').' · '.$first.'–'.end($group['keys']);

            if ($filled === []) {
                $stake += $group['points'];
                $this->add(FindingSeverity::Missing, FindingRule::Unanswered,
                    $group['label'].' — no percentages entered.',
                    location: $location,
                    hint: 'Enter the share of each source; leave a line blank if it is 0.'.($group['points'] ? ' Worth up to '.$group['points'].' points.' : ''),
                    ref: $group['ref'], question: $first, category: 'financial', pointsAtStake: $group['points']);

                continue;
            }

            $sum = array_sum($filled);
            if (abs($sum - 100) > 2) {
                $this->add(FindingSeverity::Warning, FindingRule::SumMismatch,
                    $group['label'].' adds up to '.$this->figure(round($sum, 1)).'%, not 100%.',
                    location: $location, hint: 'Check the figures with the movement’s finance lead.', category: 'financial');
            }
        }

        foreach ([['Q223', 'Q224', 'Q223–Q224 Local vs international funding'], ['Q225', 'Q226', 'Q225–Q226 Restricted vs unrestricted funding']] as [$x, $y, $label]) {
            $a = Answer::number($answers[$x] ?? null);
            $b = Answer::number($answers[$y] ?? null);
            if ($a !== null && $b !== null && abs($a + $b - 100) > 2) {
                $this->add(FindingSeverity::Warning, FindingRule::SumMismatch,
                    $label.' adds up to '.$this->figure($a + $b).'%, not 100%.',
                    location: ($form->locations[$x]['sheet'] ?? '').' · '.$x.'–'.$y,
                    hint: 'The two shares should total 100.', category: 'financial');
            }
        }

        // A debt-free YMCA only earns the debt-risk point by answering "No" to both follow-ups.
        if (Answer::isNo($answers['Q242'] ?? null) && ! (Answer::isNo($answers['Q244'] ?? null) && Answer::isNo($answers['Q245'] ?? null))) {
            $this->add(FindingSeverity::Warning, FindingRule::ScoringNote,
                'Q242 says there is no outstanding debt, but Q244 and Q245 are not both "No".',
                location: ($form->locations['Q244']['sheet'] ?? '').' · Q244–Q245',
                hint: 'The form awards this point only when both are answered "No". As entered it scores 0.',
                question: 'Q244', category: 'financial');
        }

        return $stake;
    }

    /**
     * A scoring cell with typed text in place of its formula. The form's own total then
     * leaves that answer out; this system scores it from the answer regardless.
     */
    private function checkOverwrittenScoring(ReadForm $form): void
    {
        foreach ($form->overwrittenScoring as $code => $cell) {
            $where = $form->locations[$code] ?? null;
            $this->add(FindingSeverity::Warning, FindingRule::FormulaOverwritten,
                str_replace('#2', '', $code).'’s scoring cell ('.$cell.') holds typed text instead of the form’s formula.',
                location: ($where['sheet'] ?? '').' · '.$cell,
                hint: 'The form’s printed total ignores this answer. The system scores it from the answer; ask the movement to use an unaltered form next time.',
                question: $code, category: $where['category'] ?? null);
        }
    }

    /** Figures that should agree with each other. */
    private function checkConsistency(ReadForm $form): void
    {
        $a = $form->answers;
        $n = fn (string $code) => Answer::number($a[$code] ?? null);
        $money = fn (string $code) => Answer::amount($a[$code] ?? null);

        // Operating balance = income − expenditure, within 1% of income (rounding).
        [$income, $spent, $balance] = [$money('Q211'), $money('Q212'), $money('Q213')];
        if ($income !== null && $spent !== null && $balance !== null && abs($income - $spent - $balance) > max(1, abs($income) * 0.01)) {
            $this->add(FindingSeverity::Warning, FindingRule::Inconsistent,
                'Income ('.$this->figure($income).') minus expenditure ('.$this->figure($spent).') is '.$this->figure($income - $spent).', but Q213 gives an operating balance of '.$this->figure($balance).'.',
                location: ($form->locations['Q213']['sheet'] ?? '').' · Q211–Q213',
                hint: 'Confirm the figures with the movement’s finance lead.', question: 'Q213', category: 'financial');
        }

        $board = ($n('Q315') ?? 0) + ($n('Q316') ?? 0) + ($n('Q317') ?? 0);
        $min = $n('Q313');
        $max = $n('Q314');
        if ($board && $min !== null && $max !== null && ($board < $min || $board > $max)) {
            $this->add(FindingSeverity::Warning, FindingRule::Inconsistent,
                'The board has '.$this->figure($board).' members, outside the constitutional range of '.$this->figure($min).'–'.$this->figure($max).'.',
                location: ($form->locations['Q315']['sheet'] ?? '').' · Q313–Q317',
                hint: 'Either the head count or the constitutional limits are wrong — or the board is out of compliance.', category: 'governance');
        }

        foreach (['Q318', 'Q319'] as $code) {
            if ($board && $n($code) !== null && $n($code) > $board) {
                $this->add(FindingSeverity::Warning, FindingRule::Inconsistent,
                    $code.' ('.$this->figure((float) $n($code)).') is larger than the total board of '.$this->figure($board).'.',
                    location: ($form->locations[$code]['sheet'] ?? '').' · '.$code,
                    hint: 'It is a subset of the board, so it cannot exceed it.', question: $code, category: 'governance');
            }
        }

        $total = $n('Q1008');
        $parts = [$n('Q1009'), $n('Q1010'), $n('Q1011')];
        if ($total !== null && array_filter($parts, fn ($v) => $v !== null) !== []) {
            $sum = array_sum(array_map(fn ($v) => $v ?? 0, $parts));
            if ($sum !== $total) {
                $this->add(FindingSeverity::Warning, FindingRule::Inconsistent,
                    'Permanent + temporary + project staff = '.$this->figure($sum).', but Q1008 gives a total of '.$this->figure($total).'.',
                    location: ($form->locations['Q1008']['sheet'] ?? '').' · Q1008–Q1011',
                    hint: ($sum < $total ? $this->figure($total - $sum).' staff are not accounted for' : 'The breakdown exceeds the total').'. Confirm the head count with the movement.',
                    category: 'staff');
            }
            if ($n('Q1012') !== null && $n('Q1012') > $total) {
                $this->add(FindingSeverity::Warning, FindingRule::Inconsistent,
                    'Q1012 (staff under 30: '.$this->figure((float) $n('Q1012')).') exceeds the total staff of '.$this->figure($total).'.',
                    location: ($form->locations['Q1012']['sheet'] ?? '').' · Q1012',
                    hint: 'It is a subset of the total.', question: 'Q1012', category: 'staff');
            }
        }
    }

    /** The report's opportunities for growth and the ODP priorities are drawn from these. */
    private function checkComments(ReadForm $form): void
    {
        foreach ($form->categorySheets as $code => $sheet) {
            if ($sheet === null || ($form->comments[$code]['text'] ?? null) !== null) {
                continue;
            }
            $row = $form->comments[$code]['row'] ?? null;
            $this->add(FindingSeverity::Missing, FindingRule::CommentMissing,
                $this->name($code).' — no areas of improvement given.',
                location: $sheet.($row !== null ? ' · row '.$row : ''),
                hint: '"Based on this assessment, which areas of improvement would you like your YMCA to work towards?" The report’s opportunities for growth and the ODP priorities are drawn from this answer.',
                ref: 'c:'.$code, category: $code);
        }
    }

    private function checkSignoff(ReadForm $form): void
    {
        foreach (FormDefinition::SIGNOFF as $line) {
            $got = $form->signoff[$line['label']] ?? null;
            $location = $form->generalSheet.' · Submission'.($got !== null ? ' · row '.$got['row'] : '');

            if ($got === null || ! Answer::has($got['answer'])) {
                $this->add(FindingSeverity::Missing, FindingRule::SignoffMissing,
                    'Submission — "'.$line['label'].'" participation not recorded.',
                    location: $location,
                    hint: $line['mustBeYes'] ? 'The form is final only once the NGS/CEO and the Chair have agreed it.' : 'Record whether they took part in completing the form.',
                    ref: 's:'.$line['label'], category: 'general');
            } elseif ($line['mustBeYes'] && ! Answer::isYes($got['answer'])) {
                $this->add(FindingSeverity::Missing, FindingRule::SignoffMissing,
                    'Submission — the '.$line['label'].' did not take part in completing the form.',
                    location: $location,
                    hint: 'The Welcome sheet requires the NGS/CEO and the Chair to agree the form before it is submitted to the Area Alliance.',
                    ref: 's:'.$line['label'], category: 'general');
            }
        }
    }

    /**
     * @param  array<string, int|null>  $points
     */
    private function checkPrintedTotals(ReadForm $form, array $points): void
    {
        foreach ($form->printedTotals as $code => $printed) {
            if (($points[$code] ?? null) !== null && (float) $points[$code] !== $printed) {
                $this->add(FindingSeverity::Warning, FindingRule::TotalMismatch,
                    $this->name($code).': the form prints '.$this->figure($printed).' points, but its answers score '.$points[$code].'.',
                    location: (string) $form->generalSheet,
                    hint: 'The form’s formulas may have been edited or overwritten. The system uses the score computed from the answers.',
                    category: $code);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cover(ReadForm $form): array
    {
        $a = $form->answers;

        return [
            'legal_name' => $a['Q101'] ?? null,
            'country' => $a['Q102'] ?? null,
            'address' => $a['Q104'] ?? null,
            'period' => isset($a['Q105']) ? (string) $a['Q105'] : null,
            'structure' => $a['Q112'] ?? null,
            'version' => $form->version,
            'consent' => $form->consent,
            'signoff' => array_map(fn ($s) => $s['answer'], $form->signoff),
        ];
    }

    private function refused(): CheckResult
    {
        return new CheckResult(false, $this->findings, null, [], []);
    }

    /**
     * @return list<Finding>
     */
    private function withSeverity(FindingSeverity $severity): array
    {
        return array_values(array_filter($this->findings, fn (Finding $f) => $f->severity === $severity));
    }

    private function add(
        FindingSeverity $severity,
        FindingRule $rule,
        string $message,
        string $location = '',
        string $hint = '',
        ?string $ref = null,
        ?string $question = null,
        ?string $category = null,
        int $pointsAtStake = 0,
    ): void {
        $this->findings[] = new Finding($severity, $rule, $message, $location, $hint, $ref, $question,
            $category === 'general' ? null : $category, $pointsAtStake);
    }

    private function name(string $code): string
    {
        return $this->categories[$code]['name'] ?? $code;
    }

    private function figure(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : (string) $value;
    }

    private function clip(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
