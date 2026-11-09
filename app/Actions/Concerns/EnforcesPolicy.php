<?php

namespace App\Actions\Concerns;

use App\Exceptions\WorkflowRuleBroken;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Every action checks the same policy the screens use, so a rule enforced here can never
 * drift from what the interface shows. A refusal carries the policy's reason, written
 * for the person who tried. The one way an action checks permission.
 */
trait EnforcesPolicy
{
    /**
     * @param  mixed  $arguments  the model, the model's class (create, viewAny), or [class, …] for extra arguments
     */
    private function ensure(User $user, string $ability, mixed $arguments): void
    {
        $response = Gate::forUser($user)->inspect($ability, $arguments);

        if ($response->denied()) {
            throw new WorkflowRuleBroken((string) ($response->message() ?? 'You cannot do that.'));
        }
    }
}
