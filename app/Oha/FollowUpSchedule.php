<?php

namespace App\Oha;

use App\Models\Assessment;
use App\Models\FollowUpWindow;
use App\Models\OdpFollowUp;
use Illuminate\Support\Carbon;

/**
 * An assessment's follow-ups, once it is closed: config oha.follow_ups (3), in order. No
 * fixed dates (decided 12 Nov 2026): each one's date is agreed with the movement and set
 * by its assessors, an Administrator or the Super Administrator (none: "not set yet").
 *
 *   upcoming  not opened yet: evidence added meanwhile waits for it
 *   open      opened by its assessors or an Administrator (never by itself), once the
 *             follow-up before it is closed
 *   locked    closed by its assessors or an Administrator: what it counted is kept
 *   skipped   never closed, and the cycle was closed without it
 *
 * It is overdue once its agreed date has passed and it is not closed. Nothing closes by itself.
 */
final class FollowUpSchedule
{
    public const UPCOMING = 'upcoming';

    public const OPEN = 'open';

    public const LOCKED = 'locked';

    public const SKIPPED = 'skipped';

    /** What evidence added after the last follow-up counts in: the next assessment. */
    public const NEXT_ASSESSMENT = 4;

    /**
     * @return list<array{number: int, due_on: Carbon|null, meeting: OdpFollowUp|null, window: FollowUpWindow|null, state: string, overdue: bool}> empty until the assessment is closed
     */
    public static function for(Assessment $assessment): array
    {
        if ($assessment->closed_at === null) {
            return [];
        }
        $count = max(1, (int) config('oha.follow_ups'));
        $meetings = $assessment->followUps()->with(['recorder', 'tasks'])->get()->keyBy('number');
        $windows = FollowUpWindow::query()->where('assessment_id', $assessment->id)->get()->keyBy('number');
        $cycleClosed = $assessment->isFollowUpClosed();
        $today = today();

        $schedule = [];
        $previousLocked = true;
        for ($n = 1; $n <= $count; $n++) {
            $window = $windows[$n] ?? null;
            $due = $window?->due_on;
            $state = match (true) {
                (bool) $window?->isLocked() => self::LOCKED,
                $cycleClosed => self::SKIPPED,
                $previousLocked && $window?->opened_at !== null => self::OPEN,
                default => self::UPCOMING,
            };
            $schedule[] = [
                'number' => $n,
                'due_on' => $due,
                'meeting' => $meetings[$n] ?? null,
                'window' => $window,
                'state' => $state,
                'overdue' => $due !== null && in_array($state, [self::OPEN, self::UPCOMING], true) && $today->gt($due),
            ];
            $previousLocked = $state === self::LOCKED;
        }

        return $schedule;
    }

    /**
     * The follow-up under way: the first not locked (open or upcoming), if any. Read from
     * the database each time: it changes as follow-ups are closed.
     *
     * @phpstan-impure
     *
     * @return array{number: int, due_on: Carbon|null, meeting: OdpFollowUp|null, window: FollowUpWindow|null, state: string, overdue: bool}|null
     */
    public static function next(Assessment $assessment): ?array
    {
        foreach (self::for($assessment) as $followUp) {
            if (in_array($followUp['state'], [self::OPEN, self::UPCOMING], true)) {
                return $followUp;
            }
        }

        return null;
    }

    /**
     * What evidence added to this assessment now counts in: the assessment itself (null)
     * until it is closed; then the follow-up under way; after the last one (or once the
     * cycle is closed), the next assessment.
     */
    public static function countedIn(Assessment $assessment): ?int
    {
        if (! $assessment->isClosed()) {
            return null;
        }

        return self::next($assessment)['number'] ?? self::NEXT_ASSESSMENT;
    }

    /** Whether what counts in $countedIn is locked: the closed assessment, or a locked follow-up. */
    public static function isLocked(Assessment $assessment, ?int $countedIn): bool
    {
        if ($countedIn === null) {
            return $assessment->isClosed();
        }

        return $countedIn !== self::NEXT_ASSESSMENT
            && FollowUpWindow::query()->where('assessment_id', $assessment->id)->where('number', $countedIn)->whereNotNull('locked_at')->exists();
    }

    /** What $countedIn counts in, in words: "Follow-up 2", "the assessment (Baseline 1)". */
    public static function label(?int $countedIn): string
    {
        return match ($countedIn) {
            null => 'the assessment (Baseline 1)',
            self::NEXT_ASSESSMENT => 'the next assessment',
            default => 'Follow-up '.$countedIn,
        };
    }
}
