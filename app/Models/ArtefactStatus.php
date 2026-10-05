<?php

namespace App\Models;

use App\Models\Concerns\IsDatabaseView;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An artefact's effective state, including the derived "locked", whether it is
 * published (approved at least once) and, for the ODP, whether the Board
 * Chairperson has validated it (view v_artefact_status).
 *
 * @property int $artefact_id
 * @property string $state
 * @property string $effective_state
 * @property bool $published
 * @property bool $validated
 */
#[Table(name: 'v_artefact_status', key: 'artefact_id', incrementing: false, timestamps: false)]
class ArtefactStatus extends Model
{
    use IsDatabaseView;

    /**
     * @return BelongsTo<Artefact, $this>
     */
    public function artefact(): BelongsTo
    {
        return $this->belongsTo(Artefact::class);
    }

    public function isLocked(): bool
    {
        return $this->effective_state === 'locked';
    }

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'validated' => 'boolean',
            'validated_at' => 'datetime',
        ];
    }
}
