<?php

namespace App\Oha\Report;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Reads the text of an uploaded report. A Word file keeps its structure (headings,
 * bold lines, lists, pictures); a PDF gives its lines of text only. A scanned PDF has
 * no text at all, and says so.
 */
final class ReportReader
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function read(string $path, string $format): ReadReport
    {
        try {
            return $format === 'pdf' ? $this->pdf($path) : $this->docx($path);
        } catch (Throwable) {
            return new ReadReport($format, [], problem: 'The file could not be opened to read its text.');
        }
    }

    private function docx(string $path): ReadReport
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            return new ReadReport('docx', [], problem: 'This does not look like a Word document.');
        }
        $headingStyles = $this->headingStyles((string) $zip->getFromName('word/styles.xml'));
        $zip->close();

        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        $x = new DOMXPath($doc);
        $x->registerNamespace('w', self::W);

        $paragraphs = [];
        foreach ($x->query('//w:body//w:p') as $p) {
            if (! $p instanceof DOMElement) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', implode('', array_map(fn ($t) => $t->nodeValue, iterator_to_array($x->query('.//w:t', $p))))));
            if ($text === '') {
                continue;
            }
            $style = $x->query('./w:pPr/w:pStyle/@w:val', $p)->item(0)?->nodeValue;
            $runs = $x->query('.//w:r[w:t]', $p);
            $boldRuns = $x->query('.//w:r[w:t][w:rPr/w:b[not(@w:val="0") and not(@w:val="false")]]', $p);

            $paragraphs[] = [
                'text' => $text,
                'heading' => $style !== null ? ($headingStyles[$style] ?? null) : null,
                'bold' => $runs->length > 0 && $boldRuns->length === $runs->length,
                'list' => $x->query('./w:pPr/w:numPr', $p)->length > 0 || preg_match('/^[•\-–·▪●]\s/u', $text) === 1,
            ];
        }

        $images = $x->query('//w:drawing | //w:pict')->length;

        return new ReadReport('docx', $paragraphs, $images);
    }

    /**
     * Style id => heading level, from the document's own styles ("heading 2", "Title").
     *
     * @return array<string, int>
     */
    private function headingStyles(string $stylesXml): array
    {
        $levels = [];
        if ($stylesXml === '') {
            return $levels;
        }
        $doc = new DOMDocument;
        $doc->loadXML($stylesXml, LIBXML_NONET | LIBXML_COMPACT);
        $x = new DOMXPath($doc);
        $x->registerNamespace('w', self::W);

        foreach ($x->query('//w:style[@w:type="paragraph"]') as $style) {
            if (! $style instanceof DOMElement) {
                continue;
            }
            $id = $style->getAttributeNS(self::W, 'styleId');
            $name = mb_strtolower((string) $x->query('./w:name/@w:val', $style)->item(0)?->nodeValue);
            if (preg_match('/^heading\s*(\d)$/', $name, $m)) {
                $levels[$id] = (int) $m[1];
            } elseif ($name === 'title') {
                $levels[$id] = 1;
            }
        }

        return $levels;
    }

    private function pdf(string $path): ReadReport
    {
        $pdf = (new Parser)->parseFile($path);
        $lines = preg_split('/\R/u', $pdf->getText()) ?: [];

        $paragraphs = [];
        foreach ($lines as $line) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($text === '') {
                continue;
            }
            // A PDF keeps no styles: a short line with no closing full stop reads as a title.
            $titleLike = mb_strlen($text) <= 90 && ! preg_match('/[.,;:]$/u', $text);
            $paragraphs[] = ['text' => $text, 'heading' => null, 'bold' => $titleLike, 'list' => preg_match('/^[•\-–·▪●]\s?/u', $text) === 1];
        }

        $images = 0;
        foreach ($pdf->getObjectsByType('XObject', 'Image') as $_) {
            $images++;
        }

        return new ReadReport('pdf', $paragraphs, $images,
            $paragraphs === [] ? 'The PDF has no readable text. It may be a scanned image; upload the Word file instead.' : null);
    }
}
