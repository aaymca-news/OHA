<?php

namespace App\Enums;

/**
 * Stored workflow state of an artefact. "Approved" is an Administrator's decision, and
 * is what lets the assessor go on. "Locked" is derived, and so is "Validated": the ODP
 * is validated once the Board Chairperson has signed it (see v_artefact_status).
 * For the report, "drafted" means a version is uploaded but not yet submitted.
 */
enum ArtefactState: string
{
    case NotStarted = 'not_started';
    case RulesFailed = 'rules_failed';
    case Ready = 'ready';
    case Drafted = 'drafted';
    case PendingApproval = 'pending_approval';
    case Rejected = 'rejected';
    case Approved = 'approved';

    /**
     * Label, icon and tone of a state as shown on screen, including the derived "locked".
     *
     * @return array{label: string, icon: string, tone: string}
     */
    public static function display(string $state): array
    {
        [$label, $icon, $tone] = __('oha.state.'.$state);

        return ['label' => $label, 'icon' => $icon, 'tone' => $tone];
    }
}
