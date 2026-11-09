<?php

namespace App\Oha\Report;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Writes a drafted report (DraftReport's sections, as corrected on screen) into the
 * official AAYMCA report template (Version 5), so it looks like any report written on it.
 *
 * The template's own styles, fonts, bullets and page are kept, and its two opening
 * headings. Its body is rebuilt from copies of its own paragraphs (the title, the section
 * and category headings, body text, bullets, the "Opportunities for Growth:" label), and
 * its two tables are filled with the scores, each percentage shaded as the template asks.
 * Its "What goes here" guidance is left out.
 */
final class WordReport
{
    public const TEMPLATE = 'resources/templates/YMCA-OHA-Report-Template-2026.docx';

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * The shading of a Percentage cell by its band, as the template asks and AAYMCA's reports
     * do (green, blue, orange, yellow), and red for Critical; with the text colour that reads on it.
     */
    public const BAND_FILL = [
        'excellent' => ['6AA84F', '000000'],
        'strong' => ['6D9EEB', '000000'],
        'developing' => ['E69138', '000000'],
        'atrisk' => ['F1C232', '000000'],
        'critical' => ['CC0000', 'FFFFFF'],
    ];

    private DOMDocument $doc;

    private DOMXPath $x;

    /** @var array<string, DOMElement> */
    private array $proto = [];

    /**
     * Writes the report to a new .docx file and returns its path.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    public function write(array $sections): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oha-report').'.docx';
        if (! copy(base_path(self::TEMPLATE), $path)) {
            throw new RuntimeException('The report template could not be copied.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            throw new RuntimeException('The report template could not be opened.');
        }
        $this->doc = new DOMDocument;
        $this->doc->preserveWhiteSpace = true;
        $this->doc->loadXML($xml, LIBXML_NONET);
        $this->x = new DOMXPath($this->doc);
        $this->x->registerNamespace('w', self::W);

        $body = $this->x->query('/w:document/w:body')->item(0);
        if (! $body instanceof DOMElement) {
            throw new RuntimeException('The report template has no body.');
        }
        $this->findPrototypes($body);
        $kept = [$this->proto['alliance'], $this->proto['sams']];
        $sectPr = $this->x->query('w:sectPr', $body)->item(0);

        // Empty the body, keeping the page settings, then write the report into it.
        foreach (iterator_to_array($body->childNodes) as $child) {
            if ($child !== $sectPr) {
                $body->removeChild($child);
            }
        }
        $add = fn (DOMNode $node) => $sectPr !== null ? $body->insertBefore($node, $sectPr) : $body->appendChild($node);
        foreach ($kept as $heading) {
            $add($heading);
        }
        foreach ($sections as $section) {
            foreach ($this->render($section) as $node) {
                $add($node);
            }
        }

        $zip->addFromString('word/document.xml', (string) $this->doc->saveXML());
        $zip->close();

        return $path;
    }

    /**
     * The template's own paragraphs and tables, found by what they say, to copy from.
     */
    private function findPrototypes(DOMElement $body): void
    {
        $find = function (string $what, callable $match) use ($body): void {
            foreach ($body->childNodes as $node) {
                if ($node instanceof DOMElement && $match($node, trim($node->textContent))) {
                    $this->proto[$what] = $node;

                    return;
                }
            }
            throw new RuntimeException("The report template has changed: its {$what} could not be found.");
        };
        $p = fn (DOMElement $n) => $n->localName === 'p';
        $starts = fn (string $text) => fn (DOMElement $n, string $t) => $p($n) && str_starts_with($t, $text);

        $find('alliance', $starts('AFRICA ALLIANCE OF YMCAs'));
        $find('sams', $starts('STRATEGIC ALIGNMENT AND MOVEMENT STRENGTHENING'));
        $find('title', $starts('ORGANISATIONAL HEALTH ASSESSMENT REPORT'));
        $find('section', $starts('ORGANISATIONAL OVERVIEW'));
        $find('body', $starts('[Full name of the NM]'));
        $find('overall', $starts('Using the Global YMCA'));
        $find('subheading', fn (DOMElement $n, string $t) => $p($n) && $t === 'Key Strengths');
        $find('bullet', $starts('[Strength 1'));
        $find('section_c', $starts('SECTION C'));
        $find('label', fn (DOMElement $n, string $t) => $p($n) && $t === 'Opportunities for Growth:');
        $find('spacer', fn (DOMElement $n, string $t) => $p($n) && $t === '');
        $find('scores', fn (DOMElement $n, string $t) => $n->localName === 'tbl' && str_starts_with($t, 'Category'));
        $find('overall_table', fn (DOMElement $n, string $t) => $n->localName === 'tbl' && str_starts_with($t, 'Overall Percentage'));
    }

    /**
     * The paragraphs (and tables) one section becomes.
     *
     * @param  array<string, mixed>  $s
     * @return list<DOMNode>
     */
    private function render(array $s): array
    {
        return match ($s['kind']) {
            'title' => [$this->title($s['lines']), $this->copy('spacer')],
            'heading' => [$this->paragraph('section_c', $s['title'])],
            'scores' => [$this->scores($s['rows']), $this->copy('spacer'), $this->overallTable($s['overall']), $this->copy('spacer')],
            'list' => [$this->paragraph('subheading', $s['title']), ...$this->bullets($s['items']), $this->copy('spacer')],
            'category' => [
                $this->paragraph('subheading', $s['title']),
                ...$this->paragraphs('body', $s['text']),
                $this->paragraph('label', 'Opportunities for Growth:'),
                ...$this->bullets($s['items']),
                $this->copy('spacer'),
            ],
            'custom' => [$this->paragraph('subheading', $s['title']), ...$this->paragraphs('body', $s['text']), $this->copy('spacer')],
            default => match ($s['key']) {
                // The overall sentence keeps the template's own style; the section's heading is numbered, as in the template.
                'overall' => [$this->paragraph('section', $s['title']), ...$this->paragraphs('overall', $s['text'])],
                default => [$this->paragraph('section', $s['title']), ...$this->paragraphs('body', $s['text'])],
            },
        };
    }

    /**
     * @param  list<string>  $lines
     */
    private function title(array $lines): DOMElement
    {
        $p = $this->emptied('title');
        $run = $this->firstRun($this->proto['title']);
        foreach (array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== '')) as $i => $line) {
            if ($i > 0) {
                $p->appendChild($this->doc->createElementNS(self::W, 'w:r'))->appendChild($this->doc->createElementNS(self::W, 'w:br'));
            }
            $p->appendChild($this->run($run, $line));
        }

        return $p;
    }

    /**
     * Paragraphs separated by an empty line; a single line break stays within its paragraph.
     *
     * @return list<DOMElement>
     */
    private function paragraphs(string $proto, string $text): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', str_replace("\r\n", "\n", $text)) ?: []), fn ($t) => $t !== ''));
        // After the first, the overall sentence's further paragraphs are ordinary text.
        $out = [];
        foreach ($parts as $i => $part) {
            $out[] = $this->paragraph($proto === 'overall' && $i > 0 ? 'body' : $proto, $part);
        }

        return $out;
    }

    /**
     * @param  list<string>  $items
     * @return list<DOMElement>
     */
    private function bullets(array $items): array
    {
        return array_map(fn (string $item) => $this->paragraph('bullet', $item), array_values(array_filter($items, fn ($i) => trim($i) !== '')));
    }

    /** A copy of a template paragraph, saying $text (single line breaks kept). */
    private function paragraph(string $proto, string $text): DOMElement
    {
        $p = $this->emptied($proto);
        $run = $this->firstRun($this->proto[$proto]);
        foreach (explode("\n", $text) as $i => $line) {
            if ($i > 0) {
                $p->appendChild($this->doc->createElementNS(self::W, 'w:r'))->appendChild($this->doc->createElementNS(self::W, 'w:br'));
            }
            $p->appendChild($this->run($run, $line));
        }

        return $p;
    }

    /**
     * The template's score table, one row per scored category, each percentage shaded by its band.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function scores(array $rows): DOMElement
    {
        $table = $this->copy('scores');
        $trs = iterator_to_array($this->x->query('w:tr', $table));
        array_shift($trs); // the heading row stays
        $model = $trs[0] ?? throw new RuntimeException('The template’s score table has no rows.');
        foreach ($trs as $tr) {
            $table->removeChild($tr);
        }

        foreach ($rows as $r) {
            $tr = $model->cloneNode(true);
            $cells = iterator_to_array($this->x->query('w:tc', $tr));
            $this->setCell($cells[0], (string) $r['name']);
            $this->setCell($cells[1], $this->number((float) $r['points']));
            $this->setCell($cells[2], (string) $r['max']);
            $this->setCell($cells[3], (string) $r['band_label']);
            $this->setCell($cells[4], number_format((float) $r['pct'], 2).'%');
            $this->shade($cells[4], (string) $r['band']);
            $table->appendChild($tr);
        }

        return $table;
    }

    /**
     * The template's overall table: the overall percentage, what it means, and its band, shaded.
     *
     * @param  array<string, mixed>  $overall
     */
    private function overallTable(array $overall): DOMElement
    {
        $table = $this->copy('overall_table');
        foreach ($this->x->query('.//w:tc', $table) as $cell) {
            if (! $cell instanceof DOMElement) {
                continue;
            }
            $text = trim($cell->textContent);
            if (str_starts_with($text, '[XX.XX]')) {
                $this->setCell($cell, number_format((float) $overall['pct'], 2).'%');
            } elseif (str_starts_with($text, '[Overall health label]')) {
                $this->setCell($cell, (string) $overall['band_label']);
                $this->shade($cell, (string) $overall['band']);
            } elseif (str_starts_with($text, '[Message')) {
                $this->setCell($cell, ucfirst(SentenceBank::BAND_MEANING[$overall['band']] ?? '').'.');
            }
        }

        return $table;
    }

    /** Puts $text in a table cell, in the formatting of its first run. */
    private function setCell(DOMElement $cell, string $text): void
    {
        $paragraphs = iterator_to_array($this->x->query('w:p', $cell));
        $first = $paragraphs[0];
        foreach (array_slice($paragraphs, 1) as $extra) {
            $cell->removeChild($extra);
        }
        $run = $this->firstRun($first);
        foreach (iterator_to_array($first->childNodes) as $child) {
            if ($child instanceof DOMElement && $child->localName !== 'pPr') {
                $first->removeChild($child);
            }
        }
        $first->appendChild($this->run($run, $text));
    }

    /** Shades a cell in its band's colour, with text that reads on it. */
    private function shade(DOMElement $cell, string $band): void
    {
        [$fill, $ink] = self::BAND_FILL[$band] ?? ['FFFFFF', '000000'];
        $tcPr = $this->x->query('w:tcPr', $cell)->item(0);
        if (! $tcPr instanceof DOMElement) {
            $tcPr = $cell->insertBefore($this->doc->createElementNS(self::W, 'w:tcPr'), $cell->firstChild);
        }
        $shd = $this->x->query('w:shd', $tcPr)->item(0);
        if (! $shd instanceof DOMElement) {
            $shd = $this->doc->createElementNS(self::W, 'w:shd');
            // In the schema's order: after the width and borders, before the vertical alignment.
            $before = $this->x->query('w:noWrap|w:tcMar|w:textDirection|w:tcFitText|w:vAlign|w:hideMark', $tcPr)->item(0);
            $before !== null ? $tcPr->insertBefore($shd, $before) : $tcPr->appendChild($shd);
        }
        $shd->setAttributeNS(self::W, 'w:val', 'clear');
        $shd->setAttributeNS(self::W, 'w:color', 'auto');
        $shd->setAttributeNS(self::W, 'w:fill', $fill);
        foreach ($this->x->query('.//w:rPr', $cell) as $rPr) {
            if (! $rPr instanceof DOMElement) {
                continue;
            }
            $color = $this->x->query('w:color', $rPr)->item(0);
            if ($color instanceof DOMElement) {
                $color->setAttributeNS(self::W, 'w:val', $ink);
            }
        }
    }

    private function copy(string $proto): DOMElement
    {
        $copy = $this->proto[$proto]->cloneNode(true);
        if (! $copy instanceof DOMElement) {
            throw new RuntimeException("The template’s {$proto} could not be copied.");
        }

        return $copy;
    }

    /** A copy of a template paragraph with only its paragraph properties. */
    private function emptied(string $proto): DOMElement
    {
        $p = $this->copy($proto);
        foreach (iterator_to_array($p->childNodes) as $child) {
            if (! ($child instanceof DOMElement && $child->localName === 'pPr')) {
                $p->removeChild($child);
            }
        }

        return $p;
    }

    /** The paragraph's first run that holds text, as the model for new runs. */
    private function firstRun(DOMElement $p): ?DOMElement
    {
        foreach ($this->x->query('.//w:r[w:t]', $p) as $r) {
            if ($r instanceof DOMElement) {
                return $r;
            }
        }

        return null;
    }

    /** A run saying $text, formatted like $model. */
    private function run(?DOMElement $model, string $text): DOMElement
    {
        $r = $this->doc->createElementNS(self::W, 'w:r');
        $rPr = $model !== null ? $this->x->query('w:rPr', $model)->item(0) : null;
        if ($rPr !== null) {
            $r->appendChild($rPr->cloneNode(true));
        }
        $t = $r->appendChild($this->doc->createElementNS(self::W, 'w:t'));
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($this->doc->createTextNode($text));

        return $r;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
