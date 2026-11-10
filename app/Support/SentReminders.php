<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reminders already sent, by key (for example "digest:12:2026-W46"), so a reminder is
 * sent once however often the scheduler runs, and one missed day loses none.
 */
final class SentReminders
{
    /** Whether this one was already sent. */
    public static function sent(string $key): bool
    {
        return DB::table('sent_reminders')->where('key', $key)->exists();
    }

    /**
     * Records it as sent. False if it already was (another run got there first). Skips a
     * duplicate without an error, which in PostgreSQL would spoil a surrounding transaction.
     */
    public static function mark(string $key): bool
    {
        return DB::table('sent_reminders')->insertOrIgnore(['key' => $key, 'sent_at' => now()]) === 1;
    }
}
