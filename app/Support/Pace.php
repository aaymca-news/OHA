<?php

namespace App\Support;

/**
 * The pace needed (decided 23 Nov 2026): to reach the next band up by the end of the cycle,
 * how many points each follow-up still to come must add, beside what each follow-up added so
 * far. Nothing when the movement is already in the top band or no follow-up is left.
 */
final class Pace
{
    /**
     * @param  list<float>  $scores  the scores so far: the baseline, then each follow-up score
     * @param  int  $slots  the baseline and every follow-up of a cycle
     * @return array{target: float, band: string, need: float, left: int, gained: float|null, on_course: bool|null, points: list<float|null>}|null
     */
    public static function of(array $scores, int $slots): ?array
    {
        if ($scores === []) {
            return null;
        }

        $now = (float) end($scores);
        $left = $slots - count($scores);
        $next = collect(Bands::scale())->first(fn (array $b) => $b['from'] > $now);
        if ($left <= 0 || $next === null) {
            return null;
        }

        $need = round(($next['from'] - $now) / $left, 1);
        $gained = count($scores) > 1 ? round(($now - $scores[0]) / (count($scores) - 1), 1) : null;

        // The line from the newest score to the target, one point per follow-up to come.
        $points = array_fill(0, $slots, null);
        $points[count($scores) - 1] = $now;
        for ($step = 1; $step <= $left; $step++) {
            $points[count($scores) - 1 + $step] = round($now + $need * $step, 1);
        }

        return [
            'target' => (float) $next['from'],
            'band' => $next['label'],
            'need' => $need,
            'left' => $left,
            'gained' => $gained,
            'on_course' => $gained === null ? null : $gained >= $need,
            'points' => $points,
        ];
    }
}
