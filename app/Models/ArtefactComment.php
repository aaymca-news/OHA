<?php

namespace App\Models;

use App\Enums\CommentKind;
use Database\Factories\ArtefactCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['artefact_id', 'user_id', 'kind', 'body', 'created_at'])]
#[WithoutTimestamps]
class ArtefactComment extends Model
{
    /** @use HasFactory<ArtefactCommentFactory> */
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => CommentKind::class,
            'created_at' => 'datetime',
        ];
    }
}
