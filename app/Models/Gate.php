<?php

namespace App\Models;

use Database\Factories\GateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An approval gate and its deadline, in days from the assessment opening.
 */
#[Fillable(['code', 'name', 'milestone_label', 'sort_order', 'sla_days'])]
#[WithoutTimestamps]
class Gate extends Model
{
    /** @use HasFactory<GateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sla_days' => 'integer',
        ];
    }
}
