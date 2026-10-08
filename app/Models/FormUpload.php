<?php

namespace App\Models;

use Database\Factories\FormUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded OHA form. The file itself is immutable evidence; after upload,
 * the answers stored here are the record: as read from the file (answers written
 * in words read as what they mean), with any answers typed in the platform for
 * what the file lacked on top ("supplied", with who typed them and when).
 */
#[Fillable(['artefact_id', 'disk', 'path', 'original_name', 'size_bytes', 'sha256', 'uploaded_by', 'uploaded_at', 'answers', 'form_meta', 'printed_totals', 'supplied', 'approved_supplied', 'approved_by', 'approved_at', 'edited_disk', 'edited_path', 'edited_sha256', 'edited_size_bytes'])]
#[WithoutTimestamps]
class FormUpload extends Model
{
    /** @use HasFactory<FormUploadFactory> */
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<FormFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(FormFinding::class);
    }

    /** The workbook has answers typed in the platform written into a corrected copy. */
    public function hasEdits(): bool
    {
        return $this->edited_path !== null;
    }

    /**
     * The file shown and downloaded: the corrected copy when answers were typed in the
     * platform, else the file as uploaded.
     *
     * @return array{disk: string, path: string, sha256: string, name: string}
     */
    public function shownFile(): array
    {
        return $this->hasEdits()
            ? ['disk' => (string) $this->edited_disk, 'path' => (string) $this->edited_path, 'sha256' => (string) $this->edited_sha256,
                'name' => preg_replace('/\.xlsx$/i', '', $this->original_name).' (with answers typed in the platform).xlsx']
            : ['disk' => $this->disk, 'path' => $this->path, 'sha256' => $this->sha256, 'name' => $this->original_name];
    }

    /** An Administrator approved the form as read from this upload. */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'form_meta' => 'array',
            'printed_totals' => 'array',
            'supplied' => 'array',
            'approved_supplied' => 'array',
            'uploaded_at' => 'datetime',
            'approved_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }
}
