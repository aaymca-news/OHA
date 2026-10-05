<?php

namespace App\Models;

use Database\Factories\MembershipStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'label', 'icon', 'sort_order'])]
#[WithoutTimestamps]
class MembershipStatus extends Model
{
    /** @use HasFactory<MembershipStatusFactory> */
    use HasFactory;

    /**
     * @return HasMany<Movement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }
}
