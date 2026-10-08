<?php

namespace App\Models;

use App\Enums\DocumentFormat;
use App\Enums\DocumentPurpose;
use App\Enums\DocumentSource;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A downloadable report or ODP file, stored once and never overwritten. A version
 * records its own approval, so the last approved version stays visible while a newer
 * one waits. An ODP version taken from Google Drive records the Drive version it was
 * read at, and which Google account last changed the document.
 */
#[Fillable(['artefact_id', 'purpose', 'source', 'format', 'disk', 'path', 'original_name', 'size_bytes', 'sha256', 'note', 'created_by', 'created_at', 'approved_by', 'approved_at', 'extracted', 'drive_version', 'edited_by_email', 'changes'])]
#[WithoutTimestamps]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Artefact, $this>
     */
    public function artefact(): BelongsTo
    {
        return $this->belongsTo(Artefact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function fromDrive(): bool
    {
        return $this->source === DocumentSource::GoogleDrive;
    }

    /** A version the platform made: the one before, with fixes typed here written in. */
    public function fromPlatform(): bool
    {
        return $this->source === DocumentSource::Platform;
    }

    /** Its version number among the document's versions (1 = the first). */
    public function versionNumber(): int
    {
        return Document::query()->where('artefact_id', $this->artefact_id)
            ->where('purpose', DocumentPurpose::Uploaded)->where('id', '<=', $this->id)->count();
    }

    /** A browser can show PDFs itself; Word files are drawn on the page by docx-preview. */
    public function canPreview(): bool
    {
        return in_array($this->format, [DocumentFormat::Pdf, DocumentFormat::Docx], true);
    }

    protected function casts(): array
    {
        return [
            'purpose' => DocumentPurpose::class,
            'source' => DocumentSource::class,
            'drive_version' => 'integer',
            'format' => DocumentFormat::class,
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
            'approved_at' => 'datetime',
            'extracted' => 'array',
            'changes' => 'array',
        ];
    }
}
