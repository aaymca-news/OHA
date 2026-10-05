<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Sends workflow notices to exactly the people concerned, never broadcast.
 */
final class Notify
{
    /**
     * @param  iterable<User|null>  $recipients
     */
    public static function send(iterable $recipients, WorkflowNotice $notice, ?User $except = null): void
    {
        $users = collect($recipients)
            ->filter(fn (?User $u) => $u !== null && $u->active && $u->id !== $except?->id)
            ->unique('id')
            ->values();

        if ($users->isNotEmpty()) {
            Notification::send($users, $notice);
        }
    }

    /**
     * Staff assigned to assess the assessment's movement.
     *
     * @return Collection<int, User>
     */
    public static function assessorsOf(Assessment $assessment): Collection
    {
        return $assessment->movement->assessors()->where('active', true)->get();
    }

    public static function link(Assessment $assessment): string
    {
        return '/assessments/'.$assessment->id;
    }
}
