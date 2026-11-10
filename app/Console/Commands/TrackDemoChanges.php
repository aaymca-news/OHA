<?php

namespace App\Console\Commands;

use App\Demo\DemoChanges;
use App\Demo\DemoSite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The demo site only: sets up (again) the record of changes on every table, so a
 * visitor's work can be undone when the Super Administrator deletes their account. The
 * migration does it once; run this after a later migration adds tables.
 */
#[Signature('oha:demo-track-changes')]
#[Description('Demo site only: record every change and who made it, so a visitor’s work can be undone')]
class TrackDemoChanges extends Command
{
    public function handle(): int
    {
        if (($refusal = DemoSite::refusal()) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }
        DemoChanges::install();
        $this->info('Every change on the demo is now recorded with who made it.');

        return self::SUCCESS;
    }
}
