<?php

namespace App\Support;

use App\Enums\BandCode;
use App\Models\HealthBand;

/**
 * Chart colours by the job they do, written out in full so Tailwind finds each class.
 * Categorical slots are assigned in fixed order and never cycled; the heat ramp is one
 * hue for magnitude, stepped at the health-band thresholds.
 */
final class Viz
{
    /** Fill classes for categorical slots 1–6 (see --color-viz-* in app.css). */
    public const SLOTS = ['bg-viz-1', 'bg-viz-2', 'bg-viz-3', 'bg-viz-4', 'bg-viz-5', 'bg-viz-6'];

    /** Each band's heat step: fill, and a text colour that clears contrast on it. */
    private const STEPS = [
        'excellent' => ['bg-heat-5', 'text-white'],
        'strong' => ['bg-heat-4', 'text-white'],
        'developing' => ['bg-heat-3', 'text-white'],
        'atrisk' => ['bg-heat-2', 'text-on-surface'],
        'critical' => ['bg-heat-1', 'text-on-surface'],
    ];

    /**
     * The heat step for a percentage score: fill, text colour, and the band it stands
     * for. The band limits come from the health_bands table, read once per request.
     *
     * @return array{fill: string, text: string, band: string}
     */
    public static function heat(?float $pct): array
    {
        if ($pct === null) {
            return ['fill' => 'bg-surface-container', 'text' => 'text-on-surface-variant', 'band' => 'Not rated'];
        }

        $bands = HealthBand::ordered();
        $band = $bands->first(fn (HealthBand $b) => $pct >= (float) $b->min_pct);
        [$fill, $text] = self::STEPS[$band?->code] ?? ['bg-surface-container-high', 'text-on-surface'];

        return ['fill' => $fill, 'text' => $text, 'band' => $band->label ?? 'Not started'];
    }

    /**
     * A score inside each band, for drawing the legend without writing the limits here.
     *
     * @return list<array{sample: float, label: string}>
     */
    public static function legend(): array
    {
        $bands = HealthBand::ordered();
        $upper = 100.0;
        $legend = [];
        foreach ($bands as $band) {
            $min = (float) $band->min_pct;
            // Shown as whole percentages, the way the form writes them ("1–19%").
            $legend[] = ['sample' => $min, 'label' => $band->label.($min > 0 ? ' '.max(1, (int) round($min)).'–'.(int) round($upper).'%' : '')];
            $upper = $min - 1;
        }

        return $legend;
    }

    /** The stroke colour for a band, for the score ring. Status colours: always shown with the band's label. */
    public static function bandStroke(?string $code): string
    {
        return (BandCode::tryFrom((string) $code) ?? BandCode::NotStarted)->stroke();
    }

    /**
     * A figure as people write it: up to $decimals places, with trailing zeros dropped
     * (12.50 → 12.5, 12.00 → 12). $thousands is '' where the figure is compared with or
     * written into a document (the report check, the Word report).
     */
    public static function figure(int|float|null $value, int $decimals = 2, string $thousands = ','): string
    {
        if ($value === null) {
            return '';
        }
        $text = number_format((float) $value, $decimals, '.', $thousands);

        return $decimals > 0 ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** Points out of the points available: "58 / 84" (or "58/84" with $separator '/'). */
    public static function points(int|float|null $achieved, int|float|null $available, string $separator = ' / '): string
    {
        return $achieved === null ? '—' : self::figure($achieved).$separator.self::figure($available);
    }

    /** A percentage to one decimal place: "69.0%". */
    public static function pct(int|float|null $value, int $decimals = 1): string
    {
        return $value === null ? '—' : number_format((float) $value, $decimals).'%';
    }

    /** A whole number, with thousands separated; decimals only when they matter. */
    public static function number(?float $value, int $decimals = 0): string
    {
        if ($value === null) {
            return '—';
        }

        return number_format($value, abs($value - round($value)) < 0.005 ? 0 : $decimals);
    }

    /** Compact money: 1.2M, 528K, 90,000. */
    public static function compact(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        $abs = abs($value);

        return match (true) {
            $abs >= 1e9 => round($value / 1e9, 1).'B',
            $abs >= 1e6 => round($value / 1e6, 1).'M',
            $abs >= 1e5 => round($value / 1e3).'K',
            default => number_format($value),
        };
    }
}
