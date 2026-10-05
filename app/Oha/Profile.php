<?php

namespace App\Oha;

/**
 * What a movement's OHA form says about it, beyond the score: its structure, money,
 * funding mix, board, people, policies and strategy. Everything is read from the
 * stored answers of an approved form, so it is never a second copy of the facts.
 *
 * Money stays in the movement's own currency (Q208); only ratios are compared
 * across movements.
 */
final class Profile
{
    /** Income sources, folded to six so each keeps a distinct colour (Q214–Q222). */
    public const INCOME_GROUPS = [
        'services' => ['label' => 'Paid services', 'codes' => ['Q214']],
        'public' => ['label' => 'Government & public grants', 'codes' => ['Q215', 'Q219']],
        'membership' => ['label' => 'Membership fees', 'codes' => ['Q216']],
        'giving' => ['label' => 'Donations & sponsorship', 'codes' => ['Q217', 'Q218']],
        'private' => ['label' => 'Private grants', 'codes' => ['Q220']],
        'ymca' => ['label' => 'YMCA grants & other', 'codes' => ['Q221', 'Q222']],
    ];

    /** Expenditure by type, folded to six (Q229–Q237). */
    public const EXPENSE_GROUPS = [
        'programmes' => ['label' => 'Programmes', 'codes' => ['Q229']],
        'salaries' => ['label' => 'Salaries', 'codes' => ['Q231']],
        'admin' => ['label' => 'Administration & IT', 'codes' => ['Q236']],
        'property' => ['label' => 'Property', 'codes' => ['Q232']],
        'meetings' => ['label' => 'Internal meetings', 'codes' => ['Q230']],
        'other' => ['label' => 'Other', 'codes' => ['Q233', 'Q234', 'Q235', 'Q237']],
    ];

    /** The policies the form asks about (Q412–Q430). */
    public const POLICIES = [
        'Q412' => 'Conflict of interest', 'Q413' => 'Safeguarding', 'Q414' => 'Whistleblowing',
        'Q415' => 'Diversity, equity & inclusion', 'Q416' => 'Health & safety', 'Q417' => 'Personnel / HR',
        'Q418' => 'Financial procedures', 'Q419' => 'Gift acceptance', 'Q420' => 'Cybersecurity / AI',
        'Q421' => 'Data protection', 'Q422' => 'Procurement', 'Q423' => 'Board nomination',
        'Q424' => 'Membership criteria', 'Q425' => 'Board code of conduct', 'Q426' => 'Staff code of conduct',
        'Q427' => 'Staff wellbeing', 'Q428' => 'Climate sustainability', 'Q429' => 'Anti-corruption',
        'Q430' => 'Risk management',
    ];

    /** Vision 2030 components covered by the Strategic Plan (Q621–Q627). */
    public const VISION_2030 = [
        'Q621' => 'Collective vision', 'Q622' => 'Collective mission', 'Q623' => 'Community wellbeing',
        'Q624' => 'Meaningful work', 'Q625' => 'Sustainable planet', 'Q626' => 'Just world',
        'Q627' => 'Strategic goals',
    ];

    /** World YMCA foundational statements in the constitution (Q631–Q635). */
    public const STATEMENTS = [
        'Q631' => 'Paris Basis', 'Q632' => 'Kampala Principles', 'Q633' => 'Challenge 21',
        'Q634' => 'Nairobi Statement', 'Q635' => 'World YMCA Value Statement',
    ];

    /** Good-governance practices, each a Yes/No on the form. */
    public const GOVERNANCE_PRACTICES = [
        'Q320' => 'Board recruited on skills', 'Q323' => 'Term limit for board members',
        'Q325' => 'Term limit for the Chair', 'Q325#2' => 'Board evaluates itself yearly',
        'Q327' => 'Board evaluates the CEO/GS yearly', 'Q328' => 'CEO/GS and Chair are different people',
        'Q330' => 'Temporary succession plan', 'Q331' => 'Long-term succession plan',
        'Q332' => 'Assets insured', 'Q333' => 'Management liability insured',
    ];

    /** Practices from the other sections that say most about how a movement runs. */
    public const PRACTICES = [
        'Q201' => 'Annual budget', 'Q205' => 'Independent annual audit', 'Q207' => 'Financial report public',
        'Q238' => 'Can cover 2 years of expenses', 'Q239' => 'Cash reserve', 'Q228' => 'Funding-risk mitigation plan',
        'Q512' => 'Organisation-wide M&E', 'Q516' => 'Impact measurement tools', 'Q610' => 'Strategic plan',
        'Q618' => 'Plan revised yearly', 'Q620' => 'Vision 2030 in the plan', 'Q810' => 'Public annual report',
        'Q811' => 'Up-to-date website', 'Q812' => 'Communications strategy', 'Q814' => 'Brand legally registered',
        'Q915' => 'Property development plan', 'Q1003' => 'Staff & volunteer training', 'Q1007' => 'Job descriptions',
    ];

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $meta  form_meta: comments, notes, cover
     */
    public function __construct(private readonly array $answers, private readonly array $meta = []) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity(),
            'finance' => $this->finance(),
            'income_mix' => $this->mix(self::INCOME_GROUPS),
            'expense_mix' => $this->mix(self::EXPENSE_GROUPS),
            'international_pct' => $this->pct('Q224'),
            'restricted_pct' => $this->pct('Q225'),
            'board' => $this->board(),
            'people' => $this->people(),
            'policies' => $this->checklist(self::POLICIES),
            'vision_2030' => Answer::isYes($this->answers['Q620'] ?? null) ? $this->checklist(self::VISION_2030) : null,
            'statements' => $this->checklist(self::STATEMENTS),
            'governance' => $this->checklist(self::GOVERNANCE_PRACTICES),
            'practices' => $this->checklist(self::PRACTICES),
            'beneficiaries' => $this->count('Q518'),
            'improvement' => array_filter($this->meta['comments'] ?? [], fn ($c) => is_string($c) && trim($c) !== ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(): array
    {
        return [
            'name' => $this->text('Q101'),
            'structure' => $this->text('Q112'),
            'branches' => $this->count('Q118'),
            'member_organisations' => $this->count('Q114'),
            'informal_groups' => $this->count('Q115'),
            'registered_ngo' => $this->yesNo('Q106'),
            'tax_exempt' => $this->yesNo('Q108'),
            'decision_body' => $this->text('Q310'),
            'owns_property' => $this->yesNo('Q910'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finance(): array
    {
        $income = $this->money('Q211');
        $spent = $this->money('Q212');
        // Income minus expenditure when both are given: the typed balance (Q213) is
        // sometimes rounded or has the wrong sign (the check flags that).
        $balance = $income !== null && $spent !== null ? round($income - $spent, 2) : $this->money('Q213');
        $assets = $this->money('Q209');
        $liabilities = $this->money('Q210');
        $fundraising = $this->money('Q246');

        return [
            'currency' => $this->currency(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'income' => $income,
            'expenditure' => $spent,
            'balance' => $balance,
            // Ratios: comparable across movements whatever the currency.
            'margin_pct' => $income ? round($balance / $income * 100, 1) : null,
            'liability_ratio' => $assets ? round($liabilities / $assets, 2) : null,
            'fundraising_pct' => $income && $fundraising !== null ? round($fundraising / $income * 100, 1) : null,
            'reserve' => Answer::isYes($this->answers['Q239'] ?? null) ? $this->money('Q240') : null,
            'reserve_months' => Answer::isYes($this->answers['Q239'] ?? null) ? $this->count('Q241') : null,
            'has_debt' => $this->yesNo('Q242'),
            'debt' => Answer::isYes($this->answers['Q242'] ?? null) ? $this->money('Q243') : null,
            'covers_two_years' => $this->yesNo('Q238'),
            'funder_risk' => $this->yesNo('Q227'),
            'supports_other_ymcas' => $this->yesNo('Q247'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function board(): array
    {
        $women = $this->count('Q315');
        $men = $this->count('Q316');
        $other = $this->count('Q317');
        $size = ($women ?? 0) + ($men ?? 0) + ($other ?? 0) ?: null;

        return [
            'size' => $size,
            'women' => $women,
            'men' => $men,
            'non_binary' => $other,
            'under_30' => $this->count('Q318'),
            'first_term' => $this->count('Q319'),
            'min' => $this->count('Q313'),
            'max' => $this->count('Q314'),
            'meetings' => $this->text('Q326#2'),
            'term_years' => $this->count('Q322'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function people(): array
    {
        return [
            'staff' => $this->count('Q1008'),
            'permanent' => $this->count('Q1009'),
            'temporary' => $this->count('Q1010'),
            'project' => $this->count('Q1011'),
            'staff_under_30' => $this->count('Q1012'),
            'volunteers' => $this->count('Q1013'),
            'enough_volunteers' => $this->yesNo('Q1001'),
            'enough_staff' => $this->yesNo('Q1002'),
        ];
    }

    /**
     * Shares of each group, as the movement entered them. Null when nothing was entered.
     *
     * @param  array<string, array{label: string, codes: list<string>}>  $groups
     * @return array<string, float>|null
     */
    private function mix(array $groups): ?array
    {
        $shares = [];
        foreach ($groups as $key => $group) {
            $values = array_filter(array_map(fn ($c) => Answer::number($this->answers[$c] ?? null), $group['codes']), fn ($v) => $v !== null);
            $shares[$key] = (float) array_sum($values);
        }

        return array_sum($shares) > 0 ? $shares : null;
    }

    /**
     * Each item as true (Yes), false (No) or null (not answered / not applicable).
     *
     * @param  array<string, string>  $items
     * @return array<string, bool|null>
     */
    private function checklist(array $items): array
    {
        return array_map(fn ($code) => $this->yesNo($code), array_combine(array_keys($items), array_keys($items)));
    }

    private function yesNo(string $code): ?bool
    {
        $value = $this->answers[$code] ?? null;

        return Answer::isYes($value) ? true : (Answer::isNo($value) ? false : null);
    }

    private function text(string $code): ?string
    {
        $value = $this->answers[$code] ?? null;

        return Answer::has($value) ? trim((string) $value) : null;
    }

    private function count(string $code): ?float
    {
        return Answer::number($this->answers[$code] ?? null);
    }

    private function pct(string $code): ?float
    {
        return Answer::number($this->answers[$code] ?? null);
    }

    private function money(string $code): ?float
    {
        return Answer::amount($this->answers[$code] ?? null);
    }

    /** The currency named at Q208, or the code typed beside the income figure ("USD 90,000"). */
    private function currency(): ?string
    {
        $named = $this->text('Q208');
        if ($named !== null) {
            return $named;
        }

        return preg_match('/^\s*([A-Z]{3})\b/', (string) ($this->answers['Q211'] ?? ''), $m) ? $m[1] : null;
    }
}
