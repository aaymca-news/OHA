<?php

namespace App\Models;

use App\Oha\FollowUpSchedule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of evidence in a movement's evidence library, supplied for one assessment:
 * a file (a policy, a plan, an audit, a photo), stored once and never overwritten, or a
 * link (the movement's website). It may back answers on the OHA form and tasks in the
 * ODP, and is filed under one OHA category (or General). Its movement and the
 * Secretariat see it; once shared, every movement does. It counts in the assessment
 * (Baseline 1) or, added once the assessment is closed, in the follow-up under way
 * (counted_in, see FollowUpSchedule::countedIn); once that is locked, so is it.
 *
 * @property int|null $counted_in
 */
#[Fillable(['assessment_id', 'category_id', 'title', 'url', 'format', 'disk', 'path', 'original_name', 'size_bytes', 'sha256', 'uploaded_by', 'uploaded_at', 'shared_at', 'shared_by', 'counted_in'])]
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

    /**
     * Whether what it counts in is locked (the closed assessment, or a closed follow-up): it
     * is kept as counted. Pass its assessment when already at hand (a list of its evidence).
     */
    public function isCounted(?Assessment $assessment = null): bool
    {
        return FollowUpSchedule::isLocked($assessment ?? $this->assessment, $this->counted_in);
    }

    /**
     * Where it counts, as a status pill: counted in something locked, or waiting for it.
     *
     * @return array{0: string, 1: string}
     */
    public function countedPill(?Assessment $assessment = null): array
    {
        $what = FollowUpSchedule::label($this->counted_in);

        return $this->isCounted($assessment) ? ['good', 'Counted in '.$what] : ['info', 'For '.$what];
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
            'counted_in' => 'integer',
            'shared_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }
}
