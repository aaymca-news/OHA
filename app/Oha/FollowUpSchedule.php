<?php

namespace App\Oha;

use App\Models\Assessment;
use App\Models\OdpFollowUp;
use Illuminate\Support\Carbon;

/**
 * When a signed ODP's follow-up meetings fall due: config oha.follow_ups_per_year (3)
 * evenly spread over the year from the signing, so at 4, 8 and 12 months. Each with the
 * meeting recorded for it, if one was.
 */
final class FollowUpSchedule
{
    /**
     * @return list<array{number: int, due_on: Carbon, meeting: OdpFollowUp|null}> empty until the ODP is signed
     */
    public static function for(Assessment $assessment): array
    {
        $signedAt = $assessment->odp()->first()?->signature()->value('signed_at');
        if ($signedAt === null) {
            return [];
        }
        $signed = Carbon::parse($signedAt)->startOfDay();
        $perYear = max(1, (int) config('oha.follow_ups_per_year'));
        $months = intdiv(12, $perYear);
        $meetings = $assessment->followUps()->with(['recorder', 'tasks'])->get()->keyBy('number');

        $schedule = [];
        for ($n = 1; $n <= $perYear; $n++) {
            $schedule[] = ['number' => $n, 'due_on' => $signed->copy()->addMonthsNoOverflow($n * $months), 'meeting' => $meetings[$n] ?? null];
        }

        return $schedule;
    }

    /**
     * The next meeting not yet recorded, if any.
     *
     * @return array{number: int, due_on: Carbon, meeting: OdpFollowUp|null}|null
     */
    public static function next(Assessment $assessment): ?array
    {
        return collect(self::for($assessment))->first(fn (array $m) => $m['meeting'] === null);
    }
}
