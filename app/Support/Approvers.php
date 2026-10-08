<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who is asked to approve a submission: every active Administrator or Super Administrator
 * other than the person who submitted it. An Administrator may approve their own work, so
 * an Administrator can always hand work in, even when they are the only one.
 */
final class Approvers
{
    /**
     * Everyone who may approve this person's submission, and so is told about it.
     *
     * @return Collection<int, User>
     */
    public static function for(User $submitter): Collection
    {
        return User::query()
            ->whereIn('role', [Role::Admin, Role::SuperAdmin])
            ->where('active', true)
            ->whereKeyNot($submitter->id)
            ->orderBy('name')
            ->get();
    }

    /** Whether this person can hand work in for approval right now, and why not. */
    public static function availabilityFor(User $submitter): Response
    {
        // An Administrator may approve their own work.
        if ($submitter->isAdmin() || self::for($submitter)->isNotEmpty()) {
            return Response::allow();
        }

        return Response::deny('There is no Administrator to approve this. Ask the Super Administrator to appoint one before submitting.');
    }
}
