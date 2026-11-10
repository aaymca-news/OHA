<?php

namespace App\Policies;

use App\Demo\DemoPeople;
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
        if ($target->isDeleted()) {
            return Response::deny('This account was deleted. It is kept only so the audit trail still names who did what.');
        }

        return ! $target->isAdmin() || $actor->isSuperAdmin() || $actor->id === $target->id
            ? Response::allow()
            : Response::deny('Only the Super Administrator manages an Administrator’s account.');
    }

    /**
     * Deactivating or deleting an account: as administering it, except that on the demo
     * site its own people (each a role every visitor chooses from) are kept for everyone.
     */
    public function remove(User $actor, User $target): Response
    {
        if (($administer = $this->administer($actor, $target))->denied()) {
            return $administer;
        }

        return DemoPeople::isBuiltIn($target)
            ? Response::deny('On the demo, '.$target->name.' is one of the people every visitor chooses from, so the account stays active for everyone.')
            : Response::allow();
    }

    /** Whether this person may give a role, e.g. when inviting someone. */
    public function grantRole(User $actor, Role $role): Response
    {
        if (($manage = $this->manage($actor))->denied()) {
            return $manage;
        }

        // The demo site has one Super Administrator (the showcase's), given by nobody there.
        if ($role === Role::SuperAdmin && config('oha.demo')) {
            return Response::deny('The demo has one Super Administrator, the showcase’s own: nobody is given that role here.');
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
