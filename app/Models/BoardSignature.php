<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Board Chairperson's online signature on one approved version of the ODP. Its
 * existence is what makes the document "Validated". The database refuses any change.
 */
#[Fillable(['artefact_id', 'document_id', 'signed_by', 'signed_name', 'signature_method', 'signature_text', 'signature_disk', 'signature_path', 'signature_sha256', 'document_sha256', 'comment', 'ip', 'user_agent', 'signed_at'])]
#[WithoutTimestamps]
class BoardSignature extends Model
{
    /** Signed by typing initials or a name (now the only way), rather than drawn (before 8 Oct 2026). */
    public function isTyped(): bool
    {
        return $this->signature_method === 'typed';
    }

    /**
     * @return BelongsTo<Artefact, $this>
     */
    public function artefact(): BelongsTo
    {
        return $this->belongsTo(Artefact::class);
    }

    /**
     * The version of the ODP that was signed.
     *
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }
}
