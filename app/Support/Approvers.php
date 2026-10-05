<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who approves a submission: any active Administrator or Super Administrator except
 * the person who submitted it. Nobody approves their own work, so an Administrator's
 * own submission needs a second Administrator.
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
        if (self::for($submitter)->isNotEmpty()) {
            return Response::allow();
        }

        return Response::deny($submitter->isAdmin()
            ? 'Nobody approves their own work, and there is no other Administrator to approve yours. Ask the Super Administrator to appoint another Administrator before you submit.'
            : 'There is no Administrator to approve this. Ask the Super Administrator to appoint one before submitting.');
    }
}
