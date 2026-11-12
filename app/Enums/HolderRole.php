<?php

namespace App\Enums;

/**
 * Who currently holds an open assessment (see v_work_items and turnaround_rules): the
 * assessor preparing it, the Administrators approving it, or, once the ODP is approved,
 * whoever closes it (its assessors or an Administrator).
 */
enum HolderRole: string
{
    case Assessor = 'assessor';
    case Approver = 'approver';
    case Closer = 'closer';

    public function label(): string
    {
        return __('oha.holder.'.$this->value);
    }
}
