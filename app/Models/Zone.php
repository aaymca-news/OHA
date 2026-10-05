<?php

namespace App\Models;

use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'sort_order'])]
#[WithoutTimestamps]
class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use HasFactory;

    /**
     * @return HasMany<Movement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }
}
