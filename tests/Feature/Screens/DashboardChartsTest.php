<?php

use App\Support\Pace;
use App\Support\Radar;
use App\Support\RedFlags;

/*
 * The arithmetic behind the dashboards' charts (approved on the mockup of 23 Nov 2026): the
 * pace to the next band, the health shape's corners, and the red flags.
 */

it('works out the pace to the next band by the end of the cycle', function () {
    // Baseline 64.3, Follow-up 1 77.4 (Strong): Excellent (90) in the 2 follow-ups left.
    $pace = Pace::of([64.3, 77.4], 4);

    expect($pace)->toMatchArray(['target' => 90.0, 'band' => 'Excellent', 'need' => 6.3, 'left' => 2, 'gained' => 13.1, 'on_course' => true])
        ->and($pace['points'])->toBe([null, 77.4, 83.7, 90.0]);

    // From the baseline alone: no pace so far to compare.
    expect(Pace::of([55.0], 4))->toMatchArray(['target' => 70.0, 'need' => 5.0, 'left' => 3, 'gained' => null, 'on_course' => null]);

    // Nothing above the top band, and nothing once the cycle's follow-ups are all done.
    expect(Pace::of([88.0, 93.0], 4))->toBeNull()
        ->and(Pace::of([50.0, 55.0, 60.0, 65.0], 4))->toBeNull();
});

it('places the health shape’s corners clockwise from the top', function () {
    expect(Radar::point(0, 8, 100))->toBe([310.0, 170.0])
        ->and(Radar::point(2, 8, 100))->toBe([410.0, 270.0])
        ->and(Radar::point(4, 8, 100))->toBe([310.0, 370.0])
        // A missing area sits at the centre.
        ->and(Radar::polygon([100, null, 100, null, 100, null, 100, null]))->toStartWith('310,100 310,270 480,270');
});

it('raises a red flag only where the draft thresholds say, and none it cannot tell', function () {
    $weak = ['international_pct' => 80.0, 'finance' => ['reserve_months' => 2, 'margin_pct' => -1.5]];
    expect(RedFlags::of([60.0, 58.0], $weak, 10, 3))->toBe(['no_gain' => true, 'international' => true, 'reserve' => true, 'deficit' => true, 'unproven' => true]);

    $sound = ['international_pct' => 40.0, 'finance' => ['reserve_months' => 6, 'margin_pct' => 4.0]];
    expect(RedFlags::of([60.0, 66.0], $sound, 10, 2))->toBe(['no_gain' => false, 'international' => false, 'reserve' => false, 'deficit' => false, 'unproven' => false])
        // No follow-up yet and no baseline: those two cannot be told.
        ->and(RedFlags::of([], $sound, 10, null))->toMatchArray(['no_gain' => null, 'unproven' => null]);
});
