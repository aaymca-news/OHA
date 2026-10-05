<?php

namespace App\Actions\Oha\Concerns;

use App\Exceptions\WorkflowRuleBroken;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Every action checks the same policy the screens use, so a rule enforced here
 * can never drift from what the interface shows. A refusal carries the policy's
 * reason, written for the person who tried.
 */
trait EnforcesPolicy
{
    private function ensure(User $user, string $ability, Model $subject): void
    {
        $response = Gate::forUser($user)->inspect($ability, $subject);

        if ($response->denied()) {
            throw new WorkflowRuleBroken((string) ($response->message() ?? 'You cannot do that.'));
        }
    }
}
