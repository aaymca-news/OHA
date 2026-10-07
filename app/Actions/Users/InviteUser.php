<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Models\User;
use App\Notifications\InvitationNotice;
use App\Support\AssessorChanges;
use App\Support\Audit;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * An Administrator creates an account and emails the person a link to set their
 * password. There is no public sign-up. The account cannot be used until the link
 * is followed; until then it shows as "Invitation pending". Only a Super
 * Administrator invites an Administrator.
 */
final class InviteUser
{
    use ManagesUsers;

    /**
     * @param  list<int>  $movementIds  movements an AAYMCA user is assigned to assess
     */
    public function handle(
        User $admin,
        string $name,
        string $email,
        Role $role,
        ?string $title = null,
        array $movementIds = [],
        ?Movement $chairOf = null,
        bool $confirmReplace = false,
    ): User {
        $this->ensureAdmin($admin);
        $this->ensureAllowed($admin, 'grantRole', [User::class, $role]);

        if ($role === Role::Board && $chairOf === null) {
            throw new WorkflowRuleBroken('A Board Chairperson belongs to a movement: choose which one.');
        }

        return DB::transaction(function () use ($admin, $name, $email, $role, $title, $movementIds, $chairOf, $confirmReplace): User {
            if ($role === Role::Board) {
                $this->vacateChair($admin, $chairOf, null, $confirmReplace, trim($name));
            }

            $user = User::query()->create([
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'password' => Hash::make(Str::random(64)),
                'role' => $role,
                'title' => $title !== null && trim($title) !== '' ? trim($title) : null,
                'movement_id' => $role === Role::Board ? $chairOf->id : null,
                'active' => true,
            ]);

            Audit::record($admin, 'user.invited', $user, payload: array_filter(['role' => $role->value, 'email' => $user->email, 'movement_id' => $user->movement_id]));

            if ($user->isAssessor() && $movementIds !== []) {
                $user->assignedMovements()->sync($movementIds);
                // Recorded on each movement like any assignment; the invitation itself names them.
                foreach (Movement::query()->whereKey($movementIds)->get() as $movement) {
                    AssessorChanges::record($admin, $movement, collect([$user]), collect(), tellAdded: false);
                }
            }

            $this->sendInvitation($user, $admin);

            return $user->refresh();
        });
    }

    public function sendInvitation(User $user, User $admin): void
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker('invites');
        $user->notify(new InvitationNotice(
            $broker->createToken($user),
            $admin->name,
            $user->isAssessor() ? $user->assignedMovements()->orderBy('name')->pluck('name')->all() : [],
            $user->movement?->name,
        ));
    }
}
