<?php

namespace App\Demo;

use App\Exceptions\WorkflowRuleBroken;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

/**
 * The demo site's limit on invitations, as its email is real: so many a day from each
 * private demo, and from each internet address (whatever demo it uses). Counted in the
 * main schema ("demo" cache store), shared by every private demo. Nothing off the demo.
 */
final class DemoInvitations
{
    private const DAY = 24 * 60 * 60;

    public static function allow(): void
    {
        if (! Sandboxes::enabled()) {
            return;
        }

        $limiter = new RateLimiter(Cache::store('demo'));
        $limits = [
            'demo-invites:demo:'.(Sandboxes::current() ?? 'none') => (int) config('oha.demo_invites_per_demo'),
            'demo-invites:address:'.request()->ip() => (int) config('oha.demo_invites_per_address'),
        ];
        foreach ($limits as $key => $max) {
            if ($limiter->tooManyAttempts($key, $max)) {
                throw new WorkflowRuleBroken("The demo sends at most {$max} invitations a day from here. Try again tomorrow.");
            }
        }
        foreach (array_keys($limits) as $key) {
            $limiter->hit($key, self::DAY);
        }
    }
}
