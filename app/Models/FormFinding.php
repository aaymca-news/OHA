<?php

namespace App\Models;

use App\Enums\DqaDimension;
use App\Enums\FindingSeverity;
use Database\Factories\FormFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['form_upload_id', 'severity', 'rule', 'ref', 'question_code', 'category_id', 'location', 'message', 'hint', 'points_at_stake', 'dqa_dimension', 'resolved_by', 'resolved_at', 'resolved_note'])]
#[WithoutTimestamps]
class FormFinding extends Model
{
    /** @use HasFactory<FormFindingFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<FormUpload, $this>
     */
    public function formUpload(): BelongsTo
    {
        return $this->belongsTo(FormUpload::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    protected function casts(): array
    {
        return [
            'severity' => FindingSeverity::class,
            'dqa_dimension' => DqaDimension::class,
            'resolved_at' => 'datetime',
            'points_at_stake' => 'integer',
        ];
    }
}
