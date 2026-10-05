<?php

namespace App\Oha\Report;

/**
 * What was read from an uploaded report, before any judgement is made about it:
 * its paragraphs in order (with whether each looks like a heading or a list item)
 * and how many pictures it holds. Kept on the document, so the report is checked
 * again, against the current OHA form, without reopening the file.
 */
final readonly class ReadReport
{
    /**
     * @param  list<array{text: string, heading: int|null, bold: bool, list: bool}>  $paragraphs
     *                                                                                            heading is the Word heading level (1–6), or null
     */
    public function __construct(
        public string $format,
        public array $paragraphs,
        public int $images = 0,
        public ?string $problem = null,
    ) {}

    public function readable(): bool
    {
        return $this->problem === null && $this->words() >= 50;
    }

    public function text(): string
    {
        return implode("\n", array_column($this->paragraphs, 'text'));
    }

    public function words(): int
    {
        return str_word_count($this->text());
    }

    /**
     * A paragraph that stands as a title: a Word heading, a short bold line, or a
     * short numbered line ("3. Governance", "Category 4. Monitoring").
     *
     * @param  array{text: string, heading: int|null, bold: bool, list: bool}  $p
     */
    public static function isTitle(array $p): bool
    {
        $short = mb_strlen($p['text']) <= 140;

        return ($p['heading'] !== null && $short)
            || ($p['bold'] && $short)
            || ($short && preg_match('/^\s*(?:category\s+)?\d{1,2}\s*[.)]\s+\S/i', $p['text']) === 1);
    }

    /**
     * @return array{format: string, paragraphs: list<array{text: string, heading: int|null, bold: bool, list: bool}>, images: int, problem: string|null}
     */
    public function toArray(): array
    {
        return ['format' => $this->format, 'paragraphs' => $this->paragraphs, 'images' => $this->images, 'problem' => $this->problem];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['format'] ?? ''), $data['paragraphs'] ?? [], (int) ($data['images'] ?? 0), $data['problem'] ?? null);
    }
}
