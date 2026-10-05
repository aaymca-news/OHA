<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The Administrators and the Super Administrator manage users alike, with one
 * difference: only a Super Administrator gives or takes away an Administrator
 * role, or changes an Administrator's account.
 */
class UserPolicy
{
    public function manage(User $actor): Response
    {
        return $actor->active && $actor->isAdmin()
            ? Response::allow()
            : Response::deny('Only the Administrators manage users.');
    }

    /**
     * Editing, deactivating or re-inviting someone. An Administrator's account is the
     * Super Administrator's to manage, though anyone may correct their own details.
     */
    public function administer(User $actor, User $target): Response
    {
        if (($manage = $this->manage($actor))->denied()) {
            return $manage;
        }

        return ! $target->isAdmin() || $actor->isSuperAdmin() || $actor->id === $target->id
            ? Response::allow()
            : Response::deny('Only the Super Administrator manages an Administrator’s account.');
    }

    /** Whether this person may give a role, e.g. when inviting someone. */
    public function grantRole(User $actor, Role $role): Response
    {
        if (($manage = $this->manage($actor))->denied()) {
            return $manage;
        }

        return ! $role->isAdministrator() || $actor->isSuperAdmin()
            ? Response::allow()
            : Response::deny('Only the Super Administrator gives the Administrator roles.');
    }

    /** Nobody changes their own role: that is how you lock yourself out. */
    public function changeRole(User $actor, User $target): Response
    {
        if (($administer = $this->administer($actor, $target))->denied()) {
            return $administer;
        }

        return $actor->id !== $target->id
            ? Response::allow()
            : Response::deny('You cannot change your own role.');
    }
}
