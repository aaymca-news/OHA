<?php

use App\Support\Google\GoogleDrive;
use Illuminate\Support\Facades\Schedule;

// Run by cron every minute on cPanel: `php artisan schedule:run`.

// While there is no Super Administrator, only one Administrator, or a movement
// without a Board Chairperson, remind the Administrators.
Schedule::command('oha:remind-missing-roles')->dailyAt('08:00')->timezone('Africa/Nairobi')->withoutOverlapping();

// Stage 2, ODP follow-up: the weekly digest to each General Secretary and assessor
// (tasks due and overdue, waiting for confirmation, follow-up meetings), on Monday
// mornings; and the next assessment's reminders (90 and 30 days before, then overdue).
Schedule::command('oha:follow-up-digest')->weeklyOn(1, '07:00')->timezone('Africa/Nairobi')->withoutOverlapping();
Schedule::command('oha:remind-reassessment')->dailyAt('07:30')->timezone('Africa/Nairobi')->withoutOverlapping();

// An ODP's tasks are read into Timelines as the ODP is saved; this catches up any that
// were missed (a failed read is retried; an ODP already read is skipped). Pages never read ODPs.
Schedule::command('oha:read-odp-tasks')->everyFifteenMinutes()->withoutOverlapping();

// Take the changes made to the ODPs in Google Drive, once the platform has access to it.
Schedule::command('oha:sync-drive')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => app(GoogleDrive::class)->configured());
