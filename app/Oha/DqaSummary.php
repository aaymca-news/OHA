<?php

namespace App\Oha;

use App\Enums\DqaDimension;
use App\Enums\FindingSeverity;
use App\Models\FormFinding;
use Illuminate\Support\Collection;

/**
 * The findings for an upload, grouped under the six data-quality dimensions, so
 * a validator reads a checklist with a verdict per dimension. A gap resolved on
 * the record no longer counts against its dimension.
 */
final class DqaSummary
{
    /**
     * Timeliness is a clock check, not a file check: pass whether the form missed its
     * target date (see v_assessment_milestones).
     *
     * @param  Collection<int, FormFinding>  $findings
     * @return array<string, array{verdict: string, errors: int, gaps: int, warnings: int}>
     */
    public static function of(Collection $findings, bool $late = false): array
    {
        $open = $findings->filter(fn (FormFinding $f) => $f->resolved_at === null);
        $summary = [];

        foreach (DqaDimension::cases() as $dimension) {
            $hits = $open->filter(fn (FormFinding $f) => $f->dqa_dimension === $dimension);
            $errors = $hits->where('severity', FindingSeverity::Error)->count();
            $gaps = $hits->where('severity', FindingSeverity::Missing)->count();
            $warnings = $hits->where('severity', FindingSeverity::Warning)->count()
                + ($late && $dimension === DqaDimension::Timeliness ? 1 : 0);

            $summary[$dimension->value] = [
                'verdict' => $errors ? 'fail' : ($gaps ? 'gap' : ($warnings ? 'warn' : 'pass')),
                'errors' => $errors,
                'gaps' => $gaps,
                'warnings' => $warnings,
            ];
        }

        return $summary;
    }

    /**
     * The same checklist for findings that are worked out rather than stored (the report check).
     *
     * @param  list<Finding>  $findings
     * @return array<string, array{verdict: string, errors: int, gaps: int, warnings: int}>
     */
    public static function ofFindings(array $findings): array
    {
        $summary = [];

        foreach (DqaDimension::cases() as $dimension) {
            $hits = array_filter($findings, fn (Finding $f) => $f->rule->dimension() === $dimension);
            $gaps = count(array_filter($hits, fn (Finding $f) => $f->severity === FindingSeverity::Missing));
            $warnings = count($hits) - $gaps;

            $summary[$dimension->value] = [
                'verdict' => $gaps ? 'gap' : ($warnings ? 'warn' : 'pass'),
                'errors' => 0,
                'gaps' => $gaps,
                'warnings' => $warnings,
            ];
        }

        return $summary;
    }
}
