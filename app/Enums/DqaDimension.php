<?php

namespace App\Enums;

/**
 * The six data quality dimensions AAYMCA is institutionalising.
 */
enum DqaDimension: string
{
    case Completeness = 'completeness';
    case Accuracy = 'accuracy';
    case Consistency = 'consistency';
    case Timeliness = 'timeliness';
    case Validity = 'validity';
    case Traceability = 'traceability';
}
