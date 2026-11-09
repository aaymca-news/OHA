<?php

namespace App\Console\Commands;

use App\Models\Assessment;
use App\Support\OdpTimeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reads the tasks of every movement's newest ODP into the Timelines page. A new ODP
 * version is read as it is saved; this is for the ODPs saved before that, and is
 * safe to run again (an ODP already read is skipped).
 */
#[Signature('oha:read-odp-tasks')]
#[Description('Read the tasks and deadlines of each movement’s newest ODP into Timelines')]
class ReadOdpTasks extends Command
{
    public function handle(): int
    {
        foreach (OdpTimeline::assessments() as $assessment) {
            try {
                OdpTimeline::refresh($assessment);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$assessment->movement->name}: {$e->getMessage()}");

                continue;
            }
            $assessment = Assessment::query()->withCount('odpTasks')->findOrFail($assessment->id);
            $this->line(sprintf('%-28s %3d tasks%s', $assessment->movement->name, $assessment->odp_tasks_count,
                $assessment->odp_read_problem ? '  ('.$assessment->odp_read_problem.')' : ''));
        }

        return self::SUCCESS;
    }
}
