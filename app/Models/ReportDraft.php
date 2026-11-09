<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The report the platform drafted from the OHA form, as the assessors correct it on
 * screen: its sections in order (see DraftReport), each with who saved it last.
 *
 * @property list<array<string, mixed>> $sections
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['artefact_id', 'form_upload_id', 'sections', 'created_by', 'updated_by'])]
class ReportDraft extends Model
{
    /**
     * @return BelongsTo<Artefact, $this>
     */
    public function artefact(): BelongsTo
    {
        return $this->belongsTo(Artefact::class);
    }

    /**
     * @return BelongsTo<FormUpload, $this>
     */
    public function formUpload(): BelongsTo
    {
        return $this->belongsTo(FormUpload::class);
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
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * One section by its key, or null.
     *
     * @return array<string, mixed>|null
     */
    public function section(string $key): ?array
    {
        return collect($this->sections)->firstWhere('key', $key);
    }

    /** How many "[To complete: …]" are left across the draft. */
    public function toComplete(): int
    {
        return substr_count((string) json_encode($this->sections, JSON_UNESCAPED_UNICODE), '[To complete');
    }

    protected function casts(): array
    {
        return [
            'sections' => 'array',
        ];
    }
}
