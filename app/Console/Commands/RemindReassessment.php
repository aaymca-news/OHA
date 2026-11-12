<?php

namespace App\Console\Commands;

use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\DateFormats;
use App\Support\Notify;
use App\Support\SentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A movement's first assessment, planned by an Administrator, is coming. Its assessors
 * and the Administrators are told 90 days before the month planned, 30 days before, and
 * once it is past: each once (a late run sends only the most pressing one). Not once an
 * assessment is under way. Later assessments have no due date (decided 12 Nov 2026): they
 * start once the previous cycle is closed, and closing it tells the assessors.
 */
#[Signature('oha:remind-reassessment')]
#[Description('Remind the assessors and Administrators of planned first assessments coming up')]
class RemindReassessment extends Command
{
    /** Most pressing first: [key, days before it is due (or overdue), what is said]. */
    private const STAGES = [
        ['overdue', -1, 'is overdue: it was due on'],
        ['30', 30, 'is due in a month, on'],
        ['90', 90, 'is due in three months, on'],
    ];

    public function handle(): int
    {
        $sent = 0;
        $statuses = MovementStatus::query()->whereNotNull('next_assessment_on')->with('movement')->get();

        foreach ($statuses as $status) {
            $movement = $status->movement;
            $due = Carbon::parse($status->next_assessment_on)->startOfDay();
            if ($movement->followUpPaused() || $movement->assessmentUnderWay() !== null) {
                continue;
            }

            foreach (self::STAGES as $i => [$key, $days, $said]) {
                $reached = $days < 0 ? $due->lt(today()) : $due->lte(today()->addDays($days));
                $sentKey = "reassess:{$movement->id}:{$due->toDateString()}:{$key}";
                if (! $reached || SentReminders::sent($sentKey)) {
                    continue;
                }
                // The most pressing one reached: send it, and count the milder ones as done.
                foreach (array_slice(self::STAGES, $i) as [$milder]) {
                    SentReminders::mark("reassess:{$movement->id}:{$due->toDateString()}:{$milder}");
                }
                $this->notify($movement, $said, $due, $key === 'overdue');
                $this->line("{$movement->name}: next assessment {$said} {$due->toDateString()}");
                $sent++;
                break;
            }
        }

        $this->info("Sent {$sent} reminders.");

        return self::SUCCESS;
    }

    private function notify(Movement $movement, string $said, Carbon $due, bool $overdue): void
    {
        Notify::send(
            $movement->activeAssessors()->get()->merge(User::query()->administrators()->get()),
            new WorkflowNotice(
                "{$movement->name}’s first assessment ".($overdue ? 'is past its planned date' : 'is coming up'),
                "{$movement->name}’s first OHA assessment, as planned, {$said} {$due->format(DateFormats::DAY)}. Its assessors open it from My Work when the time comes.",
                route('movements.show', $movement, false),
                $overdue ? 'serious' : 'info',
            ),
        );
    }
}
