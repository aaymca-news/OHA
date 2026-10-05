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
            $this->printedTotals, $this->signoff, $this->consent, $this->overwrittenScoring);
    }

    /**
     * @return list<string>
     */
    public function missingCategorySheets(): array
    {
        return array_keys(array_filter($this->categorySheets, fn ($name) => $name === null));
    }
}
