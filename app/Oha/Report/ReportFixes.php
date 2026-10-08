<?php

namespace App\Oha\Report;

use App\Oha\Finding;

/**
 * How each finding of the report check can be fixed in the report itself:
 *
 *   form      written in from what the platform already knows: the overall score and the
 *             category scores from the OHA form, the period from the assessment
 *   author    who wrote the report, typed
 *   section   a missing section (overview, strengths or opportunities, risks), typed
 *   category  a missing category: its analysis and its opportunities, typed
 *   growth    a category's missing opportunities for growth, typed
 *
 * Null when it cannot be fixed by writing into the report (it names another movement,
 * it is too short, it cannot be read): it is then marked as reviewed with a note.
 */
final class ReportFixes
{
    private const SECTIONS = [
        'overview' => ['Overview of the movement', 'What the movement is: its registration, structure, branches, programmes and reach.'],
        'strengths' => ['Key strengths and opportunities for growth', 'What the movement does well, and where it can grow. One per line; start a line with - for a bullet.'],
        'risks' => ['Key risks', 'The risks or challenges the assessment found. One per line; start a line with - for a bullet.'],
    ];

    /**
     * @return array{kind: string, key: string, title?: string, guide?: string}|null
     */
    public static function for(Finding $finding): ?array
    {
        $ref = $finding->ref;
        if ($ref === null || ! str_starts_with($ref, 'r:')) {
            return null;
        }
        $parts = explode(':', substr($ref, 2));
        $what = $parts[0];

        return match (true) {
            $what === 'period', $what === 'score-fix', $what === 'catscores', $what === 'catscore' => ['kind' => 'form', 'key' => $what],
            $what === 'score' && $finding->severity->value === 'warning' => ['kind' => 'form', 'key' => 'score'],
            $what === 'author' => ['kind' => 'author', 'key' => 'author', 'guide' => 'The names (and roles) of the AAYMCA staff who prepared the report, for example “Osborne Otieno and Lavine Achieng, AAYMCA”.'],
            isset(self::SECTIONS[$what]) => ['kind' => 'section', 'key' => $what, 'title' => self::SECTIONS[$what][0], 'guide' => self::SECTIONS[$what][1]],
            $what === 'cat' => ['kind' => 'category', 'key' => $parts[1] ?? '', 'guide' => 'A short analysis of the category, then its opportunities for growth. One per line; start a line with - for a bullet.'],
            $what === 'growth' => ['kind' => 'growth', 'key' => $parts[1] ?? '', 'guide' => 'What the movement can do next in this category. One per line.'],
            default => null,
        };
    }

    /** A stable key for a finding, to keep a "reviewed" mark on it across versions. */
    public static function key(Finding $finding): string
    {
        return md5($finding->rule->value.'|'.$finding->message);
    }
}
