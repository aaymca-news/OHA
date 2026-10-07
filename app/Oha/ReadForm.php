<?php

namespace App\Oha;

/**
 * Everything read from an OHA workbook, before any judgement is made about it.
 */
final readonly class ReadForm
{
    /**
     * @param  list<string>  $sheetNames
     * @param  array<string, string|null>  $categorySheets  category code => sheet name, or null if missing
     * @param  array<string, mixed>  $answers  question code => cleaned answer (null when blank)
     * @param  array<string, mixed>  $notes  question code => "Additional Information" text
     * @param  array<string, array{sheet: string, row: int, text: string, category: string}>  $locations
     * @param  array<string, array{row: int|null, text: string|null}>  $comments  category code => improvement comment
     * @param  array<string, float>  $printedTotals  category code => the total the form printed
     * @param  array<string, array{row: int, answer: mixed, mustBeYes: bool}>  $signoff
     * @param  array<string, string>  $overwrittenScoring  question code => the scoring cell (e.g. "E2") that holds typed text instead of its formula
     * @param  array<string, array{from: string, to: int|float|string}>  $interpreted  question code => what was written, and what it was read as
     * @param  array<string, array{given: string, unit: string, expected: string}>  $wrongUnits  question code => an answer in the wrong unit, not counted
     */
    public function __construct(
        public array $sheetNames,
        public ?string $generalSheet,
        public array $categorySheets,
        public ?string $version,
        public array $answers,
        public array $notes,
        public array $locations,
        public array $comments,
        public array $printedTotals,
        public array $signoff,
        public bool $consent,
        public array $overwrittenScoring = [],
        public array $interpreted = [],
        public array $wrongUnits = [],
    ) {}

    /**
     * The same form with some answers changed, for what-if checks.
     *
     * @param  array<string, mixed>  $changes
     */
    public function withAnswers(array $changes): self
    {
        return new self($this->sheetNames, $this->generalSheet, $this->categorySheets, $this->version,
            array_merge($this->answers, $changes), $this->notes, $this->locations, $this->comments,
            $this->printedTotals, $this->signoff, $this->consent, $this->overwrittenScoring,
            array_diff_key($this->interpreted, $changes), array_diff_key($this->wrongUnits, $changes));
    }

    /**
     * The form with answers read from words put in plain form (see Interpreter).
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, array{from: string, to: int|float|string}>  $interpreted
     * @param  array<string, array{given: string, unit: string, expected: string}>  $wrongUnits
     */
    public function withInterpretation(array $answers, array $interpreted, array $wrongUnits): self
    {
        return new self($this->sheetNames, $this->generalSheet, $this->categorySheets, $this->version,
            $answers, $this->notes, $this->locations, $this->comments,
            $this->printedTotals, $this->signoff, $this->consent, $this->overwrittenScoring, $interpreted, $wrongUnits);
    }

    /**
     * The form with the answers typed in the platform for what it was missing:
     * answers ("q:Q240"), percentage groups ("g:income"), areas of improvement
     * ("c:financial") and sign-off lines ("s:Key Staff"). The file is never changed;
     * these sit on top of what was read from it.
     *
     * @param  array<string, array{value: mixed}>  $supplied  finding ref => what was typed
     */
    public function withSupplied(array $supplied): self
    {
        $answers = [];
        $comments = $this->comments;
        $signoff = $this->signoff;

        foreach ($supplied as $ref => $entry) {
            [$kind, $key] = explode(':', $ref, 2) + [1 => ''];
            $value = $entry['value'];
            match ($kind) {
                'q' => $answers[$key] = $value,
                'g' => $answers = array_merge($answers, (array) $value),
                'c' => $comments[$key] = ['row' => $comments[$key]['row'] ?? null, 'text' => (string) $value],
                's' => $signoff[$key] = ['row' => $signoff[$key]['row'] ?? 0, 'answer' => $value, 'mustBeYes' => $signoff[$key]['mustBeYes'] ?? false],
                default => null,
            };
        }

        return new self($this->sheetNames, $this->generalSheet, $this->categorySheets, $this->version,
            array_merge($this->answers, $answers), $this->notes, $this->locations, $comments,
            $this->printedTotals, $signoff, $this->consent, $this->overwrittenScoring,
            array_diff_key($this->interpreted, $answers), array_diff_key($this->wrongUnits, $answers));
    }

    /**
     * @return list<string>
     */
    public function missingCategorySheets(): array
    {
        return array_keys(array_filter($this->categorySheets, fn ($name) => $name === null));
    }
}
