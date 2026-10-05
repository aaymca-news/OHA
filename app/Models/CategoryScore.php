<?php

namespace App\Models;

use Database\Factories\CategoryScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Points for one category of one assessment: the single record of a score.
 * Written once, when the form is validated (or imported), together with the
 * maximum that applied then. The database rejects any UPDATE, so a score can
 * never be edited in place. The real key is (assessment_id, category_id).
 */
#[Fillable(['assessment_id', 'category_id', 'points', 'max_points_at_scoring', 'form_upload_id', 'scored_at'])]
#[Table(key: 'assessment_id', incrementing: false, timestamps: false)]
class CategoryScore extends Model
{
    /** @use HasFactory<CategoryScoreFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<FormUpload, $this>
     */
    public function formUpload(): BelongsTo
    {
        return $this->belongsTo(FormUpload::class);
    }

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'max_points_at_scoring' => 'integer',
            'scored_at' => 'datetime',
        ];
    }
}
