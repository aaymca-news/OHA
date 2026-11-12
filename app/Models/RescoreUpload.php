<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An OHA form (Excel) re-uploaded during an open follow-up to re-score it: its answers
 * replaced the ones being re-scored. Stored once, never changed.
 *
 * @property int $follow_up_number
 * @property list<string> $changed
 * @property Carbon $uploaded_at
 */
#[Fillable(['assessment_id', 'follow_up_number', 'disk', 'path', 'original_name', 'size_bytes', 'sha256', 'note', 'changed', 'uploaded_by', 'uploaded_at'])]
#[WithoutTimestamps]
class RescoreUpload extends Model
{
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function casts(): array
    {
        return [
            'follow_up_number' => 'integer',
            'changed' => 'array',
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }
}
