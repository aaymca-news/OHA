<?php

namespace App\Console\Commands;

use App\Demo\DemoSite;
use App\Demo\Sandboxes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The demo site only, run by hand: wipes everything (database and stored files) and
 * starts the demo again with the 23 movements and the demo accounts; with --with-story,
 * also the invented story at every stage. It is not scheduled: people upload their own
 * made-up documents to the demo, and a wipe would remove them. Refuses to run unless
 * config oha.demo is on and the site's address says "demo", so it never touches live.
 */
#[Signature('oha:demo-reset {--force : Run without asking} {--with-story : Also build the invented story (movements at every stage, Stage 2 follow-up)}')]
#[Description('Demo site only: wipe the demo and start it again (optionally with the invented story)')]
class ResetDemo extends Command
{
    public function handle(): int
    {
        // Two locks: the demo setting, and the site's own address says "demo".
        if (($refusal = DemoSite::refusal()) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Wipe the demo database (the showcase, every visitor’s demo and account) and every uploaded file, and start the demo again?')) {
            return self::SUCCESS;
        }

        // Every visitor's private copy goes too (its schema and its files).
        if (Sandboxes::enabled() && Schema::hasTable('demo_sandboxes')) {
            foreach (Sandboxes::all() as $key) {
                Sandboxes::destroy($key);
            }
        }

        // The stored files (forms, reports, ODPs, evidence) go with the database.
        $disk = Storage::disk((string) config('oha.disk'));
        foreach ($disk->directories() as $directory) {
            $disk->deleteDirectory($directory);
        }
        foreach ($disk->files() as $file) {
            $disk->delete($file);
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => $this->option('with-story') ? 'Database\\Seeders\\DemoStorySeeder' : 'Database\\Seeders\\DemoSeeder', '--force' => true]);
        $this->info($this->option('with-story')
            ? 'The demo is ready again, with the invented story at every stage.'
            : 'The demo is ready again: the 23 movements and the demo accounts, no assessments.');
        $this->line('Create the showcase Super Administrator again: php artisan oha:demo-super-admin <email> "<name>"');

        return self::SUCCESS;
    }
}
