<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\FollowUpDigestNotice;
use App\Queries\FollowUpDigest;
use App\Support\SentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Stage 2: the weekly ODP follow-up digest, one email a week to each National General
 * Secretary and assessor with something on their follow-up (decided 10 Nov 2026). Sent
 * once per person per week, however often it runs; nobody with nothing to report is
 * written to.
 */
#[Signature('oha:follow-up-digest {--dry-run : List who would be written to, and send nothing}')]
#[Description('Send the weekly ODP follow-up digest to the General Secretaries and assessors')]
class SendFollowUpDigest extends Command
{
    public function handle(FollowUpDigest $digest): int
    {
        $week = now()->format('o-\WW');
        $people = User::query()->where('active', true)
            ->where(fn ($q) => $q->where('role', Role::Ngs)->orWhereHas('assignedMovements'))
            ->orderBy('name')->get();

        $sent = 0;
        foreach ($people as $user) {
            $sections = $digest->for($user);
            $key = "digest:{$user->id}:{$week}";
            if ($sections === [] || SentReminders::sent($key)) {
                continue;
            }
            $this->line(sprintf('%-28s %s', $user->name, collect($sections)->map(fn ($s) => $s['movement']->name)->join(', ')));
            if ($this->option('dry-run')) {
                continue;
            }
            if (SentReminders::mark($key)) {
                $user->notify(FollowUpDigestNotice::from($sections));
                $sent++;
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run: nothing sent.' : "Sent {$sent} digests for week {$week}.");

        return self::SUCCESS;
    }
}
