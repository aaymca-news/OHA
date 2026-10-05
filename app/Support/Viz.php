<?php

namespace App\Support;

use App\Models\HealthBand;
use Illuminate\Support\Collection;

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

    /** @var Collection<int, HealthBand>|null */
    private static ?Collection $bands = null;

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

        self::$bands ??= HealthBand::query()->orderByDesc('min_pct')->get();
        $band = self::$bands->first(fn (HealthBand $b) => $pct >= (float) $b->min_pct);
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
        self::$bands ??= HealthBand::query()->orderByDesc('min_pct')->get();
        $upper = 100.0;
        $legend = [];
        foreach (self::$bands as $band) {
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
        return match ($code) {
            'excellent', 'strong' => 'text-band-strong',
            'developing' => 'text-band-developing',
            'atrisk' => 'text-band-atrisk',
            'critical' => 'text-band-critical',
            default => 'text-band-notstarted',
        };
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
