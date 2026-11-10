<?php

namespace App\Enums;

use App\Models\Assessment;
use App\Models\WorkItem;

/**
 * Where a movement is in Stage 1, from its latest assessment: not assessed, which
 * document is under way, awaiting the board's signature, or done. Worked out, labelled
 * and coloured here only (the National Movements list, the pipeline bar, the filters).
 */
enum Stage: string
{
    case NotAssessed = 'not_assessed';
    case Form = 'form';
    case Report = 'report';
    case Odp = 'odp';
    case Sign = 'sign';
    case Complete = 'complete';
    /** Stage 2 done too: the ODP follow-up cycle is closed, ready for the next assessment. */
    case Closed = 'closed';

    /** The stage of a movement whose latest assessment is $latest (null: never assessed). */
    public static function of(?Assessment $latest): self
    {
        if ($latest === null) {
            return self::NotAssessed;
        }
        $stage = self::ofWork($latest->workItem);

        return $stage === self::Complete && $latest->isFollowUpClosed() ? self::Closed : $stage;
    }

    /** The stage of an assessment from its work item (none: its ODP is signed, so done). */
    public static function ofWork(?WorkItem $work): self
    {
        if ($work === null) {
            return self::Complete;
        }

        return $work->holder_role === HolderRole::Board ? self::Sign : self::from($work->artefact_kind->value);
    }

    /** As the National Movements list says it. */
    public function label(): string
    {
        return match ($this) {
            self::NotAssessed => 'Not yet assessed',
            self::Form => 'OHA form under way',
            self::Report => 'Report under way',
            self::Odp => 'ODP under way',
            self::Sign => 'Awaiting signature',
            self::Complete => 'Stage 1 done · follow-ups ongoing',
            self::Closed => 'Cycle complete · ready for the next assessment',
        };
    }

    /** As the pipeline bar's legend says it. */
    public function short(): string
    {
        return match ($this) {
            self::NotAssessed => 'Not yet assessed',
            self::Form => 'OHA form',
            self::Report => 'Report',
            self::Odp => 'ODP',
            self::Sign => 'Awaiting signature',
            self::Complete => 'Stage 1 done',
            self::Closed => 'Cycle complete',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::NotAssessed => 'radio_button_unchecked',
            self::Form => 'table_view',
            self::Report => 'description',
            self::Odp => 'checklist',
            self::Sign => 'draw',
            self::Complete => 'verified',
            self::Closed => 'task_alt',
        };
    }

    /** Its chip tone (see x-chip). */
    public function tone(): string
    {
        return match ($this) {
            self::NotAssessed => 'neutral',
            self::Form, self::Report, self::Odp => 'info',
            self::Sign => 'warning',
            self::Complete, self::Closed => 'good',
        };
    }

    /** Its segment's fill in the pipeline bar: grey, then one hue darkening to done. */
    public function fill(): string
    {
        return match ($this) {
            self::NotAssessed => 'bg-surface-container-high',
            self::Form => 'bg-heat-1',
            self::Report => 'bg-heat-2',
            self::Odp => 'bg-heat-3',
            self::Sign => 'bg-heat-4',
            self::Complete => 'bg-heat-5',
            self::Closed => 'bg-primary',
        };
    }
}
