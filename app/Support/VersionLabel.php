<?php

namespace App\Support;

use App\Models\Artefact;
use App\Models\Document;

/**
 * How a version of the report or ODP is labelled on screen: approved, waiting,
 * sent back, saved, or replaced by a newer one.
 */
final class VersionLabel
{
    /**
     * @return array{0: string, 1: string, 2: string} label, icon, tone
     */
    public static function of(Document $version, Artefact $artefact, ?int $latestId): array
    {
        if ($version->isApproved()) {
            return ['Approved by '.$version->approver?->name.', '.$version->approved_at?->format('j M Y'), 'task_alt', 'good'];
        }
        if ($version->id !== $latestId) {
            return ['Not approved · replaced by a newer version', 'history', 'neutral'];
        }

        return match ($artefact->state->value) {
            'pending_approval' => ['Awaiting approval', 'hourglass_top', 'warning'],
            'rejected' => ['Sent back', 'undo', 'serious'],
            default => ['Saved · not yet submitted', 'edit_note', 'info'],
        };
    }
}
