<?php

namespace App\Models;

use App\Enums\HolderRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Days the current holder of an assessment has to act once work reaches them.
 */
#[Fillable(['holder_role', 'days'])]
#[WithoutTimestamps]
class TurnaroundRule extends Model
{
    protected function casts(): array
    {
        return [
            'holder_role' => HolderRole::class,
            'days' => 'integer',
        ];
    }
}
