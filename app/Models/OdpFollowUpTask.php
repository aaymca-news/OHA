<?php

namespace App\Models;

use App\Enums\TaskProgress;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One ODP task as it stood at a follow-up meeting, with what was said about it there.
 * A copy: it does not change when the task does.
 *
 * @property TaskProgress $progress
 * @property bool $done
 */
#[Fillable(['odp_follow_up_id', 'odp_task_id', 'ref', 'task', 'progress', 'done', 'update', 'sort_order'])]
#[WithoutTimestamps]
class OdpFollowUpTask extends Model
{
    protected function casts(): array
    {
        return [
            'progress' => TaskProgress::class,
            'done' => 'boolean',
        ];
    }
}
