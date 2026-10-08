<?php

namespace App\Oha\Report;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Writes fixes into a Word report: lines added under the title (the period, the author,
 * the overall score), sections added before the category analysis, text added at the end
 * of a category's own section, and a wrong figure replaced where it is written. The rest
 * of the document is left exactly as it was.
 *
 * It finds paragraphs, titles and category sections the same way the report check reads
 * them (ReportReader, ReportChecker), so what is written is found where it is checked.
 */
final class DocxEditor
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private DOMDocument $doc;

    private DOMXPath $x;

    /** @var array<string, int> */
    private array $headingStyles;

    private function __construct(private readonly string $path)
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            throw new RuntimeException('This is not a Word document that can be changed.');
        }
        $this->headingStyles = ReportReader::headingStyles((string) $zip->getFromName('word/styles.xml'));
        $zip->close();

        $this->doc = new DOMDocument;
        $this->doc->preserveWhiteSpace = true;
        $this->doc->loadXML($xml, LIBXML_NONET);
        $this->x = ReportReader::xpath($this->doc);
    }

    /** Opens a Word file for changing, in place (give it a copy). */
    public static function open(string $path): self
    {
        return new self($path);
    }

    /**
     * Lines under the title: after the document's first paragraph, where the report
     * check looks for the period and the author.
     *
     * @param  list<array{text: string, kind: string}>  $lines
     */
    public function addUnderTitle(array $lines): void
    {
        $first = ReportReader::paragraphs($this->x, $this->headingStyles)[0]['node'] ?? null;
        $first !== null ? $this->insertAfter($this->block($first), $lines) : $this->append($lines);
    }

    /**
     * Sections that come before the category analysis (overview, strengths, risks): just
     * before the first category's title, or at the end if there is none.
     *
     * @param  list<array{text: string, kind: string}>  $lines
     */
    public function addBeforeCategories(array $lines): void
    {
        foreach (ReportReader::paragraphs($this->x, $this->headingStyles) as $p) {
            if (ReadReport::isTitle($p['p']) && ReportChecker::categoryNamedBy($p['p']['text']) !== null) {
                $this->insertBefore($this->block($p['node']), $lines);

                return;
            }
        }
        $this->append($lines);
    }

    /**
     * Text at the end of a category's section (before the next category's title). False
     * if the report has no section for that category.
     *
     * @param  list<array{text: string, kind: string}>  $lines
     */
    public function addToCategory(string $code, array $lines): bool
    {
        $last = $this->sections()[$code] ?? null;
        if ($last === null) {
            return false;
        }
        $this->insertAfter($this->block($last), $lines);

        return true;
    }

    /** Whether the report has a section for the category. */
    public function hasCategory(string $code): bool
    {
        return isset($this->sections()[$code]);
    }

    /**
     * Replaces a figure where it is written (a pattern such as /61\.9\s*%/ with "69%"): in a
     * category's section, or in a paragraph matching $near anywhere. Only the first is
     * replaced. False if it is not written in one piece of text.
     */
    public function replace(string $pattern, string $to, ?string $category = null, ?string $near = null): bool
    {
        $current = null;
        foreach (ReportReader::paragraphs($this->x, $this->headingStyles) as $p) {
            if (ReadReport::isTitle($p['p']) && ($code = ReportChecker::categoryNamedBy($p['p']['text'])) !== null) {
                $current = $code;

                continue;
            }
            if (($category !== null && $current !== $category) || ($near !== null && preg_match($near, $p['p']['text']) !== 1)) {
                continue;
            }
            foreach ($this->x->query('.//w:t', $p['node']) as $t) {
                if (preg_match($pattern, (string) $t->nodeValue) === 1) {
                    $t->nodeValue = (string) preg_replace($pattern, $to, (string) $t->nodeValue, 1);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{text: string, kind: string}>  $lines
     */
    public function append(array $lines): void
    {
        $body = $this->x->query('//w:body')->item(0) ?? throw new RuntimeException('The Word document has no body.');
        $sectPr = $this->x->query('./w:sectPr', $body)->item(0);
        foreach ($lines as $line) {
            $body->insertBefore($this->paragraph($line), $sectPr);
        }
    }

    public function save(): void
    {
        $zip = new ZipArchive;
        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('The Word document could not be saved.');
        }
        $zip->addFromString('word/document.xml', (string) $this->doc->saveXML());
        $zip->close();
    }

    /**
     * Each category's last paragraph, as the report check reads its sections.
     *
     * @return array<string, DOMElement>
     */
    private function sections(): array
    {
        $last = [];
        $current = null;
        foreach (ReportReader::paragraphs($this->x, $this->headingStyles) as $p) {
            $code = ReadReport::isTitle($p['p']) ? ReportChecker::categoryNamedBy($p['p']['text']) : null;
            if ($code !== null) {
                $current = $code;
            }
            if ($current !== null) {
                $last[$current] = $p['node'];
            }
        }

        return $last;
    }

    /** The element directly in the body that holds this paragraph (itself, or the table it is in). */
    private function block(DOMElement $node): DOMNode
    {
        $block = $node;
        while ($block->parentNode !== null && $block->parentNode->localName !== 'body') {
            $block = $block->parentNode;
        }

        return $block;
    }

    /**
     * @param  list<array{text: string, kind: string}>  $lines
     */
    private function insertAfter(DOMNode $after, array $lines): void
    {
        foreach (array_reverse($lines) as $line) {
            $after->parentNode?->insertBefore($this->paragraph($line), $after->nextSibling);
        }
    }

    /**
     * @param  list<array{text: string, kind: string}>  $lines
     */
    private function insertBefore(DOMNode $before, array $lines): void
    {
        foreach ($lines as $line) {
            $before->parentNode?->insertBefore($this->paragraph($line), $before);
        }
    }

    /**
     * A new paragraph: a heading (in the document's own heading style, and bold), a bold
     * line, a bullet, or plain text.
     *
     * @param  array{text: string, kind: string}  $line
     */
    private function paragraph(array $line): DOMElement
    {
        $p = $this->doc->createElementNS(self::W, 'w:p');
        $style = $line['kind'] === 'heading' ? $this->headingStyle() : null;
        if ($style !== null) {
            $pPr = $this->doc->createElementNS(self::W, 'w:pPr');
            $pStyle = $this->doc->createElementNS(self::W, 'w:pStyle');
            $pStyle->setAttributeNS(self::W, 'w:val', $style);
            $pPr->appendChild($pStyle);
            $p->appendChild($pPr);
        }

        $r = $this->doc->createElementNS(self::W, 'w:r');
        if (in_array($line['kind'], ['heading', 'bold'], true)) {
            $rPr = $this->doc->createElementNS(self::W, 'w:rPr');
            $rPr->appendChild($this->doc->createElementNS(self::W, 'w:b'));
            $r->appendChild($rPr);
        }
        $t = $this->doc->createElementNS(self::W, 'w:t');
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($this->doc->createTextNode(($line['kind'] === 'bullet' ? '• ' : '').$line['text']));
        $r->appendChild($t);
        $p->appendChild($r);

        return $p;
    }

    /** The document's own second-level heading style (or the nearest it has). */
    private function headingStyle(): ?string
    {
        foreach ([2, 3, 1] as $level) {
            $id = array_search($level, $this->headingStyles, true);
            if ($id !== false) {
                return (string) $id;
            }
        }

        return null;
    }
}
