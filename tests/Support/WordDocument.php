<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Builds a small Word document for the report-check tests: one paragraph per
 * line, with "#", "##" or "###" for headings, "**" for a bold line and "- " for a
 * list item, and optionally a picture.
 */
final class WordDocument
{
    /**
     * @param  list<string>  $lines
     */
    public static function make(array $lines, bool $picture = false): string
    {
        $body = '';
        foreach ($lines as $line) {
            $style = $bold = $list = '';
            if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
                $style = '<w:pStyle w:val="Heading'.strlen($m[1]).'"/>';
                $line = $m[2];
            } elseif (preg_match('/^\*\*(.*)\*\*$/', $line, $m)) {
                $bold = '<w:rPr><w:b/></w:rPr>';
                $line = $m[1];
            } elseif (str_starts_with($line, '- ')) {
                $list = '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>';
                $line = substr($line, 2);
            }
            $pPr = $style || $list ? '<w:pPr>'.$style.$list.'</w:pPr>' : '';
            $body .= '<w:p>'.$pPr.'<w:r>'.$bold.'<w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1).'</w:t></w:r></w:p>';
        }
        if ($picture) {
            $body .= '<w:p><w:r><w:drawing/></w:r></w:p>';
        }

        $styles = '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';
        foreach ([1, 2, 3] as $n) {
            $styles .= '<w:style w:type="paragraph" w:styleId="Heading'.$n.'"><w:name w:val="heading '.$n.'"/></w:style>';
        }
        $styles .= '</w:styles>';

        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->addFromString('word/styles.xml', $styles);
        $zip->close();

        return $path;
    }

    /**
     * A complete report for Zambia in the 2026 layout, with the score the form gives.
     *
     * @param  array<string, string|null>  $replace  line => replacement (null drops the line)
     * @return list<string>
     */
    public static function zambiaReport(array $replace = []): array
    {
        $filler = 'The movement has a clear plan, an active board and a growing programme, with room to strengthen its systems, reserves and reporting over the coming year.';
        $lines = [
            '# AFRICA ALLIANCE OF YMCAs (AAY)',
            '### ORGANISATIONAL HEALTH ASSESSMENT REPORT ZAMBIA YMCA February 2026: Report By: Osborne and Lavine AAYMCA',
            '## ORGANISATIONAL OVERVIEW',
            'The National Council of Zambia YMCAs is a registered association with eight branches. '.str_repeat($filler.' ', 4),
            '## OVERALL ORGANISATIONAL HEALTH STATUS AND KEY PRIORITIES',
            'Zambia YMCA attained an overall score of 58/84, which is 69.0%. '.str_repeat($filler.' ', 3),
            '### Key Strengths', '- Strong strategic alignment.', '- Effective governance.',
            '### Key Risks', '- Limited reserves.', '- Low staffing.',
            '### Opportunities for Growth', '- Property development.', '- Fundraising systems.',
            '**SECTION C: DETAILED CATEGORY ANALYSIS**',
        ];
        foreach (['1. Financial Stability', '2. Governance', '3. Constitution, By-Laws, Policies and Procedures', '4. Monitoring and Evaluation',
            '5. Strategic Planning and Mission', '6. Diversity and Youth Participation', '7. Communications and Branding',
            '8. Property Management', '9. Staff and Volunteer Development'] as $title) {
            array_push($lines, '### '.$title, $filler, '**Opportunities for Growth:**', '- A first step for '.$title.'.', '- A second step.');
        }

        $out = [];
        foreach ($lines as $line) {
            if (array_key_exists($line, $replace)) {
                if ($replace[$line] !== null) {
                    $out[] = $replace[$line];
                }

                continue;
            }
            $out[] = $line;
        }

        return $out;
    }
}
