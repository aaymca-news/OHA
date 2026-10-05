<?php

namespace App\Models;

use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\DocumentPurpose;
use Database\Factories\ArtefactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The OHA form, report or ODP of an assessment. Its stored state never says "locked",
 * "published" or "validated": all three are derived (see status()). Approval by an
 * Administrator lets the assessor go on and makes it visible to all AAYMCA staff; the
 * Board Chairperson's signature (the ODP only) makes it validated.
 *
 * The ODP is written in Google Drive: it carries the link to that document, and what
 * the platform last read from it.
 */
#[Fillable([
    'assessment_id', 'kind', 'state',
    'submitted_by', 'submitted_at', 'returned_at', 'approved_by', 'approved_at',
    'gap_ack', 'gap_ack_reason',
    'drive_file_id', 'drive_url', 'drive_linked_by', 'drive_linked_at', 'drive_version', 'drive_checked_at', 'drive_problem',
])]
class Artefact extends Model
{
    /** @use HasFactory<ArtefactFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The Board Chairperson's signature, which makes the ODP validated.
     *
     * @return HasOne<BoardSignature, $this>
     */
    public function signature(): HasOne
    {
        return $this->hasOne(BoardSignature::class);
    }

    public function isApproved(): bool
    {
        return $this->state === ArtefactState::Approved;
    }

    /**
     * Approved at least once, so visible to every AAYMCA staff member (and, for the
     * report and ODP, to the Board Chairperson). For a report this stays true while a
     * newer version waits for approval: the last approved version is the one shown.
     */
    public function isPublished(): bool
    {
        return (bool) $this->status?->published;
    }

    /**
     * @return HasMany<FormUpload, $this>
     */
    public function uploads(): HasMany
    {
        return $this->hasMany(FormUpload::class);
    }

    /**
     * The current upload is simply the most recent one.
     *
     * @return HasOne<FormUpload, $this>
     */
    public function currentUpload(): HasOne
    {
        return $this->hasOne(FormUpload::class)->latestOfMany();
    }

    /**
     * Who linked the ODP's Google Drive document.
     *
     * @return BelongsTo<User, $this>
     */
    public function driveLinker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drive_linked_by');
    }

    public function isSigned(): bool
    {
        return $this->signature()->exists();
    }

    /**
     * Downloadable files: versions of the report or ODP, or files supplied for reference.
     *
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * The versions of the report or ODP, oldest first (version 1, 2, …).
     *
     * @return HasMany<Document, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Document::class)->where('purpose', DocumentPurpose::Uploaded)->orderBy('id');
    }

    /**
     * The newest version of the report or ODP: the one that is submitted and approved.
     *
     * @return HasOne<Document, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(Document::class)->where('purpose', DocumentPurpose::Uploaded)->latestOfMany();
    }

    /**
     * The newest version an Administrator approved: what staff and the Chairperson see.
     *
     * @return HasOne<Document, $this>
     */
    public function approvedVersion(): HasOne
    {
        return $this->hasOne(Document::class)->ofMany(['id' => 'max'], fn ($q) => $q
            ->where('purpose', DocumentPurpose::Uploaded)->whereNotNull('approved_at'));
    }

    /**
     * @return HasMany<ArtefactComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ArtefactComment::class);
    }

    /**
     * @return HasOne<ArtefactStatus, $this>
     */
    public function status(): HasOne
    {
        return $this->hasOne(ArtefactStatus::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => ArtefactKind::class,
            'state' => ArtefactState::class,
            'gap_ack' => 'boolean',
            'submitted_at' => 'datetime',
            'returned_at' => 'datetime',
            'approved_at' => 'datetime',
            'drive_linked_at' => 'datetime',
            'drive_checked_at' => 'datetime',
            'drive_version' => 'integer',
        ];
    }
}
