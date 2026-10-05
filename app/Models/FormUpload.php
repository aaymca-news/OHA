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
 * the answers stored here are the record.
 */
#[Fillable(['artefact_id', 'disk', 'path', 'original_name', 'size_bytes', 'sha256', 'uploaded_by', 'uploaded_at', 'answers', 'form_meta', 'printed_totals'])]
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
     * @return HasMany<FormFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(FormFinding::class);
    }

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'form_meta' => 'array',
            'printed_totals' => 'array',
            'uploaded_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }
}
