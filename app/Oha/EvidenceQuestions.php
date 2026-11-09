<?php

namespace App\Oha;

/**
 * The questions on the OHA form whose "Yes" evidence can prove: a plan, a policy, an
 * audit, a registration, a website. Each gets a short name, what exactly is needed, and
 * what kind of evidence it takes: a document, a link, or either. A "Yes" to one of these
 * without evidence linked is shown as missing.
 */
final class EvidenceQuestions
{
    public const DOCUMENT = 'document';

    public const LINK = 'link';

    public const EITHER = 'either';

    /** Questions proven by something other than a document. Every other question takes a document. */
    public const ACCEPTS = [
        'Q207' => self::EITHER,
        'Q515' => self::EITHER,
        'Q516' => self::EITHER,
        'Q810' => self::EITHER,
        'Q811' => self::LINK,
    ];

    /** Question code => exactly what proves a "Yes". The policies (Q412–Q430) follow one pattern (see needed()). */
    public const NEEDED = [
        'Q201' => 'The approved budget for the current financial year.',
        'Q202' => 'A recent financial report comparing actual income and spending with the budget.',
        'Q203' => 'The internal controls procedure, or the part of the finance manual that sets them out.',
        'Q204' => 'The latest audit report from a funder or a project audit.',
        'Q205' => 'The latest audited financial statements, signed by the independent auditor.',
        'Q207' => 'A link to the published annual financial report, or the published report itself.',
        'Q228' => 'The plan for coping if a major funder stops its funding.',
        'Q239' => 'A bank or financial statement that shows the cash reserve.',
        'Q325#2' => 'The latest board self-evaluation report, or the minutes recording it.',
        'Q327' => 'The latest performance appraisal of the CEO / General Secretary, or the board minutes approving it.',
        'Q329' => 'The CEO / General Secretary succession plan.',
        'Q330' => 'The emergency (temporary) succession plan.',
        'Q331' => 'The long-term succession plan.',
        'Q332' => 'The insurance policy or certificate covering the key properties and assets.',
        'Q333' => 'The board or management liability insurance policy or certificate.',
        'Q410' => 'The current constitution or statute, as registered.',
        'Q512' => 'The organisation-wide monitoring and evaluation framework or procedure.',
        'Q514' => 'The latest organisation-wide needs assessment, or the procedure for it.',
        'Q515' => 'A link to where knowledge is recorded and shared, or a sample record.',
        'Q516' => 'The impact measurement tool, or a recent report produced with it (a link or a document).',
        'Q610' => 'The current Strategic Plan.',
        'Q618' => 'The minutes or report of the latest annual review of the Strategic Plan.',
        'Q620' => 'The Strategic Plan, showing where it reflects Vision 2030.',
        'Q711' => 'The constitution clause or policy that sets youth representation.',
        'Q712' => 'The constitution clause or policy that sets gender representation.',
        'Q714' => 'The procedure or initiative that ensures board members take part equally.',
        'Q716' => 'The policy or system for the equal participation of young people, volunteers and staff.',
        'Q810' => 'The latest annual report, or a link to where it is published.',
        'Q811' => 'A link to the YMCA’s website.',
        'Q812' => 'The communications strategy.',
        'Q814' => 'The certificate registering the brand, logo or trademark.',
        'Q912' => 'The title deeds or registration documents of the properties.',
        'Q915' => 'The property development plan.',
        'Q1003' => 'The training plan or programme for staff and volunteers.',
        'Q1004' => 'A sample employment contract, with personal details removed.',
        'Q1007' => 'The job descriptions, or a sample of them.',
    ];

    /** Question code => the document that proves a "Yes". */
    public const LABELS = [
        // Financial Stability
        'Q201' => 'Annual budget',
        'Q202' => 'Financial reports against the budget',
        'Q203' => 'Internal controls',
        'Q204' => 'Project audit reports',
        'Q205' => 'Annual independent audit',
        'Q207' => 'Published annual financial report',
        'Q228' => 'Funding risk mitigation plan',
        'Q239' => 'Cash reserve',
        // Governance
        'Q325#2' => 'Board performance evaluation',
        'Q327' => 'CEO / General Secretary evaluation',
        'Q329' => 'Succession plan',
        'Q330' => 'Temporary succession plan',
        'Q331' => 'Long-term succession plan',
        'Q332' => 'Insurance of key properties and assets',
        'Q333' => 'Management liability insurance',
        // Constitution, by-laws, policies and procedures
        'Q410' => 'Constitution / statute',
        'Q412' => 'Conflict of Interest Policy',
        'Q413' => 'Safeguarding Policy',
        'Q414' => 'Whistleblowing Policy',
        'Q415' => 'Diversity, Equity and Inclusion Policy',
        'Q416' => 'Health & Safety Policy',
        'Q417' => 'Personnel / HR Policy',
        'Q418' => 'Financial Procedures Manual',
        'Q419' => 'Gift Acceptance Policy',
        'Q420' => 'Cybersecurity / AI Policy',
        'Q421' => 'Data Protection and Privacy Policy',
        'Q422' => 'Procurement Policy',
        'Q423' => 'Board Nomination / Election Procedure',
        'Q424' => 'Membership Criteria',
        'Q425' => 'Code of Conduct for Board Members',
        'Q426' => 'Code of Conduct for Staff and Volunteers',
        'Q427' => 'Staff and Volunteer Wellbeing Policy',
        'Q428' => 'Climate Sustainability Policy',
        'Q429' => 'Anti-corruption Policy',
        'Q430' => 'Risk Management Policy / Plan',
        // Monitoring & Evaluation
        'Q512' => 'Organisation-wide monitoring and evaluation',
        'Q514' => 'Organisation-wide needs assessment',
        'Q515' => 'Knowledge records',
        'Q516' => 'Impact measurement tools',
        // Strategic Planning
        'Q610' => 'Strategic Plan',
        'Q618' => 'Annual Strategic Plan review',
        'Q620' => 'Vision 2030 in the Strategic Plan',
        // Diversity & Youth Participation
        'Q711' => 'Youth representation provisions',
        'Q712' => 'Gender representation provisions',
        'Q714' => 'Equal participation of board members',
        'Q716' => 'Inclusion policies or systems',
        // Communications & Branding
        'Q810' => 'Annual report',
        'Q811' => 'Website',
        'Q812' => 'Communications strategy',
        'Q814' => 'Brand registration',
        // Property Management
        'Q912' => 'Property registration documents',
        'Q915' => 'Property development plan',
        // Staff & Volunteer Development
        'Q1003' => 'Staff and volunteer training',
        'Q1004' => 'Employment contracts',
        'Q1007' => 'Job descriptions',
    ];

    public static function label(string $code): ?string
    {
        return self::LABELS[$code] ?? null;
    }

    /** Exactly what proves a "Yes" to the question. */
    public static function needed(string $code): ?string
    {
        if (isset(self::NEEDED[$code])) {
            return self::NEEDED[$code];
        }
        $label = self::label($code);

        return $label !== null ? "The {$label}, as approved by the board." : null;
    }

    /** What kind of evidence the question takes: DOCUMENT, LINK or EITHER. */
    public static function accepts(string $code): string
    {
        return self::ACCEPTS[$code] ?? self::DOCUMENT;
    }

    /** The code as people read it: "Q325#2" is the form's second Q325. */
    public static function shown(string $code): string
    {
        return str_replace('#2', ' (2nd)', $code);
    }

    /**
     * Category code => its evidence questions (code => label), in the form's order.
     *
     * @return array<string, array<string, string>>
     */
    public static function byCategory(): array
    {
        $grouped = [];
        foreach (FormDefinition::categories() as $category => $definition) {
            foreach ($definition['questions'] as $question) {
                if (isset(self::LABELS[$question->code])) {
                    $grouped[$category][$question->code] = self::LABELS[$question->code];
                }
            }
        }

        return $grouped;
    }

    /** The category a question is on, or null. */
    public static function categoryOf(string $code): ?string
    {
        foreach (self::byCategory() as $category => $questions) {
            if (isset($questions[$code])) {
                return $category;
            }
        }

        return null;
    }
}
