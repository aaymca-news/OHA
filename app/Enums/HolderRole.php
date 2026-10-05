<?php

namespace App\Enums;

/**
 * Who currently holds an open assessment (see v_work_items and turnaround_rules).
 */
enum HolderRole: string
{
    case Assessor = 'assessor';
    case Approver = 'approver';
    case Board = 'board';

    public function label(): string
    {
        return __('oha.holder.'.$this->value);
    }
}
