<?php

namespace App\Support;

/**
 * A small PDF writer for the platform's own reports (the cycle journey), written by hand as
 * the Word report is, so nothing more is installed. A4 portrait pages; text in the PDF
 * standard fonts Helvetica and Helvetica-Bold (Windows-1252: every accent and typographic
 * mark the platform writes), measured with their published widths so it wraps; and the
 * shapes a chart needs: lines, rectangles, circles, a polyline and pie slices.
 *
 * Positions are in points from the page's top-left corner, as on screen; the writer turns
 * them into PDF space (from the bottom-left). Colours are hex ("#231f20").
 */
final class PdfDocument
{
    public const WIDTH = 595.28;

    public const HEIGHT = 841.89;

    /** Helvetica's widths (1/1000 of the size) for the characters 32 to 126. */
    private const REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** Helvetica-Bold's widths for the characters 32 to 126. */
    private const BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    /** Widths of the Windows-1252 marks beyond 126 that the platform writes: [regular, bold]. */
    private const MARKS = [
        0x80 => [556, 556], 0x85 => [1000, 1000], 0x91 => [222, 278], 0x92 => [222, 278],
        0x93 => [333, 500], 0x94 => [333, 500], 0x95 => [350, 350], 0x96 => [556, 556],
        0x97 => [1000, 1000], 0xA0 => [278, 278], 0xB0 => [400, 400], 0xB7 => [278, 278],
    ];

    /** Characters with no Windows-1252 form, written as their nearest plain equivalent. */
    private const PLAIN = ['→' => '->', '←' => '<-', '−' => '-', '≥' => '>=', '≤' => '<=', '✓' => '', '×' => 'x'];

    /** @var list<string> each page's drawing operators */
    private array $pages = [];

    private int $page = -1;

    public function __construct(private readonly string $title) {}

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->page = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Draws on page $index (0-based) from now on: for headers and footers added at the end. */
    public function onPage(int $index): void
    {
        $this->page = $index;
    }

    /** Text on one line, its baseline at $y; aligned left, right (ending at $x) or centred on $x. */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, string $color = '#231f20', string $align = 'left'): void
    {
        $encoded = self::encode($text);
        if ($encoded === '') {
            return;
        }
        $width = $this->measure($encoded, $size, $bold);
        $left = match ($align) {
            'right' => $x - $width,
            'center' => $x - $width / 2,
            default => $x,
        };
        $this->draw(sprintf('BT /%s %s Tf %s rg %s %s Td (%s) Tj ET',
            $bold ? 'F2' : 'F1', self::n($size), self::rgb($color), self::n($left), self::n(self::HEIGHT - $y), self::escape($encoded)));
    }

    /**
     * Text wrapped to $width, its first baseline at $y. Returns the y below its last line.
     */
    public function paragraph(float $x, float $y, float $width, string $text, float $size = 10, bool $bold = false, string $color = '#231f20', float $leading = 1.35): float
    {
        foreach ($this->wrap($text, $width, $size, $bold) as $line) {
            $this->text($x, $y, $line, $size, $bold, $color);
            $y += $size * $leading;
        }

        return $y;
    }

    /**
     * The lines $text takes at $width.
     *
     * @return list<string>
     */
    public function wrap(string $text, float $width, float $size = 10, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $block) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($block)) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($line !== '' && $this->width($candidate, $size, $bold) > $width) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** The width of $text at $size, in points. */
    public function width(string $text, float $size = 10, bool $bold = false): float
    {
        return $this->measure(self::encode($text), $size, $bold);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, string $color = '#231f20'): void
    {
        $this->draw(sprintf('%s RG %s w 1 J %s %s m %s %s l S',
            self::rgb($color), self::n($width), self::n($x1), self::n(self::HEIGHT - $y1), self::n($x2), self::n(self::HEIGHT - $y2)));
    }

    public function rect(float $x, float $y, float $w, float $h, ?string $fill = null, ?string $stroke = null, float $width = 0.5): void
    {
        $this->draw(sprintf('%s%s%s re %s',
            $fill !== null ? self::rgb($fill).' rg ' : '',
            $stroke !== null ? self::rgb($stroke).' RG '.self::n($width).' w ' : '',
            self::n($x).' '.self::n(self::HEIGHT - $y - $h).' '.self::n($w).' '.self::n($h),
            self::paint($fill, $stroke)));
    }

    public function circle(float $cx, float $cy, float $r, ?string $fill = null, ?string $stroke = null, float $width = 1): void
    {
        $path = $this->arcPath($cx, $cy, $r, 0, 360, moveTo: true);
        $this->draw(sprintf('%s%s%s h %s',
            $fill !== null ? self::rgb($fill).' rg ' : '',
            $stroke !== null ? self::rgb($stroke).' RG '.self::n($width).' w ' : '',
            $path, self::paint($fill, $stroke)));
    }

    /**
     * A line through the points, with round joins and caps.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public function polyline(array $points, float $width = 2, string $color = '#231f20'): void
    {
        if (count($points) < 2) {
            return;
        }
        $ops = [];
        foreach ($points as $i => [$x, $y]) {
            $ops[] = self::n($x).' '.self::n(self::HEIGHT - $y).($i === 0 ? ' m' : ' l');
        }
        $this->draw(sprintf('%s RG %s w 1 j 1 J %s S', self::rgb($color), self::n($width), implode(' ', $ops)));
    }

    /** A pie slice from $from to $to degrees, clockwise from twelve o'clock. */
    public function slice(float $cx, float $cy, float $r, float $from, float $to, string $fill): void
    {
        if ($to - $from <= 0) {
            return;
        }
        if ($to - $from >= 360) {
            $this->circle($cx, $cy, $r, $fill);

            return;
        }
        $this->draw(sprintf('%s rg %s %s m %s h f', self::rgb($fill), self::n($cx), self::n(self::HEIGHT - $cy),
            $this->arcPath($cx, $cy, $r, $from, $to, moveTo: false)));
    }

    /** The finished document. */
    public function output(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '', // the page tree, once the pages are numbered
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => sprintf('<< /Title (%s) /Producer (AAYMCA OHA platform) /CreationDate (D:%s) >>', self::escape(self::encode($this->title)), now()->format('YmdHis')),
        ];
        $kids = [];
        foreach ($this->pages as $content) {
            $pageId = count($objects) + 1;
            $contentId = $pageId + 1;
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::n(self::WIDTH), self::n(self::HEIGHT), $contentId);
            $objects[$contentId] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content);
            $kids[] = $pageId.' 0 R';
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.sprintf("trailer\n<< /Size %d /Root 1 0 R /Info 5 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }

    private function draw(string $operators): void
    {
        if ($this->page < 0) {
            $this->addPage();
        }
        $this->pages[$this->page] .= $operators."\n";
    }

    /** Bézier arcs of at most 90° each, from $from to $to degrees clockwise from twelve o'clock. */
    private function arcPath(float $cx, float $cy, float $r, float $from, float $to, bool $moveTo): string
    {
        $point = fn (float $deg) => [$cx + $r * sin(deg2rad($deg)), $cy - $r * cos(deg2rad($deg))];
        $segments = (int) max(1, ceil(($to - $from) / 90));
        $step = ($to - $from) / $segments;
        [$x0, $y0] = $point($from);
        $ops = [self::n($x0).' '.self::n(self::HEIGHT - $y0).($moveTo ? ' m' : ' l')];
        for ($i = 0; $i < $segments; $i++) {
            $a1 = $from + $i * $step;
            $a2 = $a1 + $step;
            $k = 4 / 3 * tan(deg2rad($step) / 4) * $r;
            [$x1, $y1] = $point($a1);
            [$x2, $y2] = $point($a2);
            // The tangent at an angle (screen space, y down), as the angle grows: (cos, sin).
            $c1 = [$x1 + $k * cos(deg2rad($a1)), $y1 + $k * sin(deg2rad($a1))];
            $c2 = [$x2 - $k * cos(deg2rad($a2)), $y2 - $k * sin(deg2rad($a2))];
            $ops[] = implode(' ', array_map(self::n(...), [$c1[0], self::HEIGHT - $c1[1], $c2[0], self::HEIGHT - $c2[1], $x2, self::HEIGHT - $y2])).' c';
        }

        return implode(' ', $ops);
    }

    private function measure(string $encoded, float $size, bool $bold): float
    {
        $units = 0;
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($encoded[$i]);
            $units += match (true) {
                $code >= 32 && $code <= 126 => ($bold ? self::BOLD : self::REGULAR)[$code - 32],
                isset(self::MARKS[$code]) => self::MARKS[$code][$bold ? 1 : 0],
                default => $code >= 0xC0 ? 611 : 556,
            };
        }

        return $units * $size / 1000;
    }

    /** UTF-8 to Windows-1252, the standard fonts' encoding. */
    private static function encode(string $text): string
    {
        $plain = strtr(trim((string) preg_replace('/[\x00-\x09\x0B-\x1F]/u', '', $text)), self::PLAIN);

        return (string) mb_convert_encoding($plain, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $encoded): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $encoded);
    }

    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        return implode(' ', array_map(fn (string $pair) => self::n(hexdec($pair) / 255), str_split(str_pad($hex, 6, '0'), 2)));
    }

    private static function paint(?string $fill, ?string $stroke): string
    {
        return match (true) {
            $fill !== null && $stroke !== null => 'B',
            $fill !== null => 'f',
            default => 'S',
        };
    }

    /** A number as PDF writes it: up to 3 decimals, no trailing zeros. */
    private static function n(float|int $value): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }
}
