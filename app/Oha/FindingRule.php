<?php

namespace App\Oha;

use App\Enums\DqaDimension;

/**
 * Every check the OHA form reader can raise, and the data-quality dimension it belongs to.
 */
enum FindingRule: string
{
    case Unreadable = 'unreadable';
    case MissingSheet = 'missing_sheet';
    case EmptyForm = 'empty_form';
    case MissingSection = 'missing_section';
    case Unanswered = 'unanswered';
    case ConditionalMissing = 'conditional_missing';
    case CommentMissing = 'comment_missing';
    case MovementMismatch = 'movement_mismatch';
    case TotalMismatch = 'total_mismatch';
    case SumMismatch = 'sum_mismatch';
    case Inconsistent = 'inconsistent';
    case InvalidOption = 'invalid_option';
    case NonNumeric = 'non_numeric';
    case OutOfRange = 'out_of_range';
    case FormVersion = 'form_version';
    case ScoringNote = 'scoring_note';
    case SignoffMissing = 'signoff_missing';
    case LateSubmission = 'late_submission';
    case FormulaOverwritten = 'formula_overwritten';

    // The report check (App\Oha\Report\ReportChecker).
    case ReportUnreadable = 'report_unreadable';
    case ReportSectionMissing = 'report_section_missing';
    case ReportCategoryMissing = 'report_category_missing';
    case ReportScoreMismatch = 'report_score_mismatch';
    case ReportScoreUnreadable = 'report_score_unreadable';
    case ReportPeriodMismatch = 'report_period_mismatch';
    case ReportAuthorMissing = 'report_author_missing';

    public function dimension(): DqaDimension
    {
        return match ($this) {
            self::Unreadable, self::MissingSheet, self::EmptyForm, self::MissingSection,
            self::Unanswered, self::ConditionalMissing, self::CommentMissing,
            self::ReportSectionMissing, self::ReportCategoryMissing => DqaDimension::Completeness,
            self::MovementMismatch, self::TotalMismatch, self::FormulaOverwritten, self::ReportScoreMismatch => DqaDimension::Accuracy,
            self::SumMismatch, self::Inconsistent => DqaDimension::Consistency,
            self::InvalidOption, self::NonNumeric, self::OutOfRange, self::FormVersion, self::ScoringNote,
            self::ReportUnreadable, self::ReportScoreUnreadable => DqaDimension::Validity,
            self::SignoffMissing, self::ReportAuthorMissing => DqaDimension::Traceability,
            self::LateSubmission, self::ReportPeriodMismatch => DqaDimension::Timeliness,
        };
    }
}
