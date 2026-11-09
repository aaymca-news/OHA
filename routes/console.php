<?php

use App\Support\Google\GoogleDrive;
use Illuminate\Support\Facades\Schedule;

// Run by cron every minute on cPanel: `php artisan schedule:run`.

// While there is no Super Administrator, only one Administrator, or a movement
// without a Board Chairperson, remind the Administrators.
Schedule::command('oha:remind-missing-roles')->dailyAt('08:00')->timezone('Africa/Nairobi')->withoutOverlapping();

// An ODP's tasks are read into Timelines as the ODP is saved; this catches up any that
// were missed (a failed read is retried; an ODP already read is skipped). Pages never read ODPs.
Schedule::command('oha:read-odp-tasks')->everyFifteenMinutes()->withoutOverlapping();

// Take the changes made to the ODPs in Google Drive, once the platform has access to it.
Schedule::command('oha:sync-drive')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => app(GoogleDrive::class)->configured());
