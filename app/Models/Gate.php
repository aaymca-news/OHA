<?php

namespace App\Models;

use Database\Factories\GateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Every gate in order, read once per request.
     *
     * @return Collection<int, self>
     */
    public static function ordered(): Collection
    {
        return once(fn () => self::query()->orderBy('sort_order')->get());
    }

    protected function casts(): array
    {
        return [
            'sla_days' => 'integer',
        ];
    }
}
