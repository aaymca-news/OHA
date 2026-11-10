<?php

namespace App\Console\Commands;

use App\Demo\Sandboxes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The demo site only: deletes the visitors' private copies unused for two weeks
 * (config oha.demo_sandbox_days), with their files. Run daily by the scheduler.
 */
#[Signature('oha:demo-prune')]
#[Description('Demo site only: delete the private demo copies unused for two weeks')]
class PruneDemoSandboxes extends Command
{
    public function handle(): int
    {
        if (! Sandboxes::enabled()) {
            $this->info('Not the demo site: nothing to do.');

            return self::SUCCESS;
        }
        $this->info('Deleted '.Sandboxes::prune().' unused demo copies; '.count(Sandboxes::all()).' remain.');

        return self::SUCCESS;
    }
}
