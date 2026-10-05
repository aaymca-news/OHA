<?php

namespace App\Console\Commands;

use App\Notifications\WorkflowNotice;
use App\Support\Notify;
use App\Support\SystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Reminds the Administrators, every day, while the workflow is missing a role it
 * needs: a Super Administrator, a second Administrator, or a Board Chairperson for
 * any movement.
 */
#[Signature('oha:remind-missing-roles')]
#[Description('Remind the Administrators of roles that still need appointing')]
class RemindMissingRoles extends Command
{
    public function handle(): int
    {
        $health = SystemHealth::check();

        if ($health['ok']) {
            $this->info('Every required role is held. No reminder sent.');

            return self::SUCCESS;
        }

        $body = implode(' ', $health['problems']);
        if ($health['movements_without_chair'] !== []) {
            $body .= ' Without a Chairperson: '.implode(', ', $health['movements_without_chair']).'.';
        }
        $body .= ' They are appointed in Users & Roles.';

        $audience = SystemHealth::audience();
        Notify::send($audience, new WorkflowNotice('Reminder: roles still to be appointed', $body, '/admin/users', 'serious'));

        $this->info("Reminded {$audience->count()} people: {$body}");

        return self::SUCCESS;
    }
}
