<?php

namespace App\Enums;

/**
 * A health band's code (health_bands.code), and how it is shown. The bands' names, limits
 * and reassessment intervals stay in the health_bands table; what colour each one wears
 * is decided here, once.
 */
enum BandCode: string
{
    case Excellent = 'excellent';
    case Strong = 'strong';
    case Developing = 'developing';
    case AtRisk = 'atrisk';
    case Critical = 'critical';
    case NotStarted = 'notstarted';

    /** The bands that call for support: at risk or critical. */
    public const NEEDING_SUPPORT = ['atrisk', 'critical'];

    /** Its tone for chips, bars and callouts (see x-chip). */
    public function tone(): string
    {
        return match ($this) {
            self::Excellent, self::Strong => 'good',
            self::Developing => 'warning',
            self::AtRisk => 'serious',
            self::Critical => 'critical',
            self::NotStarted => 'neutral',
        };
    }

    /** The tone for a band code from the database, or 'neutral' when there is none. */
    public static function toneOf(?string $code): string
    {
        return self::tryFrom((string) $code)?->tone() ?? 'neutral';
    }

    /** The stroke colour for the score ring. Always shown with the band's label. */
    public function stroke(): string
    {
        return match ($this) {
            self::Excellent, self::Strong => 'text-band-strong',
            self::Developing => 'text-band-developing',
            self::AtRisk => 'text-band-atrisk',
            self::Critical => 'text-band-critical',
            self::NotStarted => 'text-band-notstarted',
        };
    }
}
