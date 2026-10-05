<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One automatic check of the ODPs linked to Google Drive: how many were looked at,
 * how many new versions were taken, and what failed. Shown on the dashboard, so a
 * sync that has stopped working is noticed.
 */
#[Fillable(['started_at', 'finished_at', 'checked', 'saved', 'failed', 'error'])]
#[WithoutTimestamps]
class DriveSyncRun extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'checked' => 'integer',
            'saved' => 'integer',
            'failed' => 'integer',
        ];
    }
}
