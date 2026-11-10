<?php

namespace App\Support;

use App\Models\HealthBand;

/**
 * The health bands as a scale from 0 to 100, lowest first: where each one starts and
 * ends. Read once per request, for the band scale and the category bars.
 */
final class Bands
{
    /**
     * @return list<array{code: string, label: string, from: float, to: float}>
     */
    public static function scale(): array
    {
        return once(function (): array {
            $bands = HealthBand::query()->where('min_pct', '>', 0)->orderBy('min_pct')->get(['code', 'label', 'min_pct'])->values();

            return $bands->map(fn (HealthBand $b, int $i) => [
                'code' => (string) $b->code,
                'label' => (string) $b->label,
                // The lowest band starts at 0 (its 0.01 only keeps "Not started" apart).
                'from' => $i === 0 ? 0.0 : (float) $b->min_pct,
                'to' => isset($bands[$i + 1]) ? (float) $bands[$i + 1]->min_pct : 100.0,
            ])->all();
        });
    }

    /** Where a band starts, e.g. 50 for Developing; null when there is no such band. */
    public static function from(string $code): ?float
    {
        return collect(self::scale())->firstWhere('code', $code)['from'] ?? null;
    }

    /**
     * The band a score falls in, e.g. a regional average.
     *
     * @return array{code: string, label: string, from: float, to: float}|null
     */
    public static function at(float $pct): ?array
    {
        return collect(self::scale())->first(fn (array $b) => $pct >= $b['from'] && ($pct < $b['to'] || $b['to'] >= 100));
    }

    /** The status-pill tone for a band code. */
    public static function tone(?string $code): string
    {
        return match ($code) {
            'excellent', 'strong' => 'good',
            'developing' => 'warning',
            'atrisk' => 'atrisk',
            'critical' => 'critical',
            default => 'neutral',
        };
    }

    /** The bar colour (a Tailwind class) for a band code. */
    public static function fill(?string $code): string
    {
        return match ($code) {
            'excellent', 'strong' => 'bg-band-strong',
            'developing' => 'bg-band-developing',
            'atrisk' => 'bg-band-atrisk',
            'critical' => 'bg-band-critical',
            default => 'bg-band-notstarted',
        };
    }
}
