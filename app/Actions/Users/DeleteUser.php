<?php

namespace App\Actions\Users;

use App\Exceptions\WorkflowRuleBroken;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deletes an account, once deactivated: it leaves Users & Roles for good, its email is
 * freed (the person can be invited again, as a new account), and its sign-in, photo,
 * assignments and notifications go. The row itself stays, with the person's name, so the
 * audit trail, approvals and signatures still say who did what (the database refuses to
 * delete a user the history refers to). The deletion is recorded with who did it. As
 * deactivating: nobody deletes their own account; an Administrator's account is the
 * Super Administrator's to delete.
 */
final class DeleteUser
{
    use ManagesUsers;

    public function handle(User $admin, User $user): void
    {
        $this->ensure($admin, 'remove', $user);
        if ($admin->id === $user->id) {
            throw new WorkflowRuleBroken('You cannot delete your own account.');
        }
        if ($user->active) {
            throw new WorkflowRuleBroken("Deactivate {$user->name}’s account first, then delete it.");
        }

        DB::transaction(function () use ($admin, $user): void {
            $was = ['email' => $user->email, 'role' => $user->role->value, 'movement_id' => $user->movement_id];
            [$disk, $photo] = [$user->avatar_disk, $user->avatar_path];

            $user->assignedMovements()->detach();
            $user->notifications()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill([
                'deleted_at' => now(),
                // The email is freed for a new account; the old one is kept in the audit trail.
                'email' => 'deleted-'.$user->id.'-'.Str::lower(Str::random(8)).'@deleted.invalid',
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'avatar_disk' => null,
                'avatar_path' => null,
            ])->save();

            Audit::record($admin, 'user.deleted', $user, payload: array_filter($was));

            if ($disk !== null && $photo !== null) {
                Storage::disk($disk)->delete($photo);
            }
        });
    }
}
