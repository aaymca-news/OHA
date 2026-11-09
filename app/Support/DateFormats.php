<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Closure;

/**
 * The platform's date formats, added to every date (Carbon::mixin in AppServiceProvider),
 * so a page writes $date->asDay() instead of spelling the format out each time.
 *
 *   asDay()    9 Nov 2026
 *   asStamp()  9 Nov 2026, 14:05
 *   asMonth()  Nov 2026
 */
final class DateFormats
{
    public const DAY = 'j M Y';

    public const STAMP = 'j M Y, H:i';

    public const MONTH = 'M Y';

    /** @return Closure(): string */
    public function asDay(): Closure
    {
        return fn (): string => DateFormats::format($this, DateFormats::DAY);
    }

    /** @return Closure(): string */
    public function asStamp(): Closure
    {
        return fn (): string => DateFormats::format($this, DateFormats::STAMP);
    }

    /** @return Closure(): string */
    public function asMonth(): Closure
    {
        return fn (): string => DateFormats::format($this, DateFormats::MONTH);
    }

    /** Inside a mixin's closure, $this is the date it was called on (and self is the date class, so this class is named). */
    public static function format(object $date, string $format): string
    {
        return $date instanceof CarbonInterface ? $date->format($format) : '';
    }
}
