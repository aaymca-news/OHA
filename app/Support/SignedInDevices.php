<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The browsers and devices a person is signed in on (sessions live in the database),
 * described plainly: "Chrome on Windows". Each is known on the page by a key made from
 * its session id, never the id itself, which would let someone take over the session.
 */
final class SignedInDevices
{
    /**
     * @return Collection<int, array{key: string, current: bool, ip: string|null, device: string, last_active: Carbon}>
     */
    public static function of(User $user, string $currentSessionId): Collection
    {
        return DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get()
            ->map(fn (object $s) => [
                'key' => self::key($s->id),
                'current' => $s->id === $currentSessionId,
                'ip' => is_string($s->ip_address) ? $s->ip_address : null,
                'device' => self::describe((string) $s->user_agent),
                'last_active' => Carbon::createFromTimestamp($s->last_activity),
            ])
            // This device first.
            ->sortByDesc('current')->values();
    }

    public static function key(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    /** "Chrome on Windows", "Safari on iPhone"; "Unknown browser" when it cannot tell. */
    public static function describe(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'CrOS') => 'Chromebook',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => "A browser on {$system}",
            default => 'Unknown browser',
        };
    }
}
