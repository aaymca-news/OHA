<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of evidence in a movement's evidence library, supplied for one assessment:
 * a file (a policy, a plan, an audit, a photo), stored once and never overwritten, or a
 * link (the movement's website). It may back answers on the OHA form and tasks in the
 * ODP, and is filed under one OHA category (or General). Its movement and the
 * Secretariat see it; once shared, every movement does.
 */
#[Fillable(['assessment_id', 'category_id', 'title', 'url', 'format', 'disk', 'path', 'original_name', 'size_bytes', 'sha256', 'uploaded_by', 'uploaded_at', 'shared_at', 'shared_by'])]
#[WithoutTimestamps]
class Evidence extends Model
{
    protected $table = 'evidence';

    /**
     * The kinds of file accepted: documents, spreadsheets, presentations, images and
     * text. No programs or archives.
     */
    public const FORMATS = [
        'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt',
        'xls', 'xlsx', 'ods', 'csv',
        'ppt', 'pptx', 'odp',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
    ];

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * NULL: General, not one OHA category.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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
    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    public function isShared(): bool
    {
        return $this->shared_at !== null;
    }

    /** A link rather than a file. */
    public function isLink(): bool
    {
        return $this->url !== null;
    }

    /** What it is, in a few words: the file's type and size, or the link's site. */
    public function describe(): string
    {
        return $this->isLink()
            ? 'Link · '.(parse_url((string) $this->url, PHP_URL_HOST) ?: $this->url)
            : strtoupper((string) $this->format).' · '.number_format((int) $this->size_bytes / 1024).' KB';
    }

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'shared_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }
}
