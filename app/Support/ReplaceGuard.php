<?php

namespace App\Support;

use App\Enums\ArtefactKind;
use App\Enums\DocumentPurpose;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Document;
use App\Models\FormUpload;
use App\Models\User;

/**
 * Keeps several assessors on one movement from undoing each other's work.
 *
 * A page that uploads or submits says which version was current when it was opened
 * ("seen"). If someone has saved another since, the action stops and says who and when,
 * so nobody replaces or submits work they have not seen. Replacing the current version
 * takes a tick, and replacing a colleague's a reason too; both go into the audit trail.
 */
final class ReplaceGuard
{
    /** The current OHA form upload, or the newest version of the report or ODP. */
    public static function current(Artefact $artefact): FormUpload|Document|null
    {
        return $artefact->kind === ArtefactKind::Form
            ? $artefact->uploads()->with('uploader')->latest('id')->first()
            : $artefact->documents()->where('purpose', DocumentPurpose::Uploaded)->with('creator')->latest('id')->first();
    }

    /** Who saved it, and when: "Kofi Mensah, 3 Nov at 14:07". */
    public static function savedBy(FormUpload|Document $saved): string
    {
        [$who, $when] = $saved instanceof FormUpload
            ? [$saved->uploader?->name, $saved->uploaded_at]
            : [$saved->fromDrive() ? ($saved->edited_by_email ?? 'Google Drive') : $saved->creator?->name, $saved->created_at];

        return ($who ?? 'Someone').', '.$when->format('j M \a\t H:i');
    }

    /** "version 2" or "the OHA form (ZAM26.xlsx)". */
    public static function describe(FormUpload|Document $saved): string
    {
        return $saved instanceof FormUpload ? "the OHA form ({$saved->original_name})" : 'version '.$saved->versionNumber();
    }

    /** The user who saved it; null for a change taken from Google Drive. */
    public static function savedById(FormUpload|Document $saved): ?int
    {
        return $saved instanceof FormUpload ? $saved->uploaded_by : ($saved->fromDrive() ? null : $saved->created_by);
    }

    /**
     * Stops if something was saved after the page was opened. $seen is the id of what was
     * current then (0 or null: nothing was).
     */
    public static function ensureSeen(Artefact $artefact, ?int $seen, string $doing): void
    {
        $current = self::current($artefact);
        if ((int) ($current->id ?? 0) === (int) ($seen ?? 0)) {
            return;
        }

        throw new WorkflowRuleBroken($current === null
            ? "What you saw has since been removed. Reload the page before {$doing}."
            : ucfirst(self::describe($current)).' was saved by '.self::savedBy($current).", after you opened this page. Reload the page to see it before {$doing}.");
    }

    /**
     * Before an upload: the page was up to date, and replacing what is current was
     * confirmed, with a reason when it is a colleague's. Returns what the audit trail
     * keeps about the replacement.
     *
     * @return array<string, string>
     */
    public static function forUpload(Artefact $artefact, User $user, ?int $seen, bool $confirmed, ?string $reason): array
    {
        self::ensureSeen($artefact, $seen, 'replacing it');

        $current = self::current($artefact);
        if ($current === null) {
            return [];
        }

        $what = self::describe($current);
        if (! $confirmed) {
            throw new WorkflowRuleBroken("Tick the box to confirm you are replacing {$what}.");
        }
        $reason = trim((string) $reason);
        $colleagues = self::savedById($current) !== $user->id;
        if ($colleagues && mb_strlen($reason) < 3) {
            throw new WorkflowRuleBroken("Say why you are replacing {$what}, saved by ".self::savedBy($current).'.');
        }

        return array_filter([
            'replaced' => $what.' saved by '.self::savedBy($current),
            'reason' => $reason !== '' ? $reason : null,
        ]);
    }
}
