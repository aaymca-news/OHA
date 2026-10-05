<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OHA category. max_points is the single source of its weight; NULL means
 * "not yet weighted" and the category is left out of the total.
 */
#[Fillable(['code', 'name', 'short_name', 'icon', 'form_order', 'max_points'])]
#[WithoutTimestamps]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * @return HasMany<CategoryScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(CategoryScore::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeWeighted(Builder $query): void
    {
        $query->whereNotNull('max_points');
    }

    protected function casts(): array
    {
        return [
            'max_points' => 'integer',
            'form_order' => 'integer',
        ];
    }
}
