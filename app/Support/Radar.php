<?php

namespace App\Support;

/**
 * The health shape (decided 23 Nov 2026): the scored areas as the corners of a regular
 * polygon (8 areas: an octagon), the first at the top, then clockwise in form order. A score
 * runs from the centre (0%) to the corner (100%). Rings mark where the bands begin.
 */
final class Radar
{
    /** The drawing's frame (SVG units) and where the shape sits in it. */
    public const WIDTH = 620;

    public const HEIGHT = 540;

    public const CX = 310;

    public const CY = 270;

    public const RADIUS = 170;

    /** The rings: where the bands begin, then the edge. */
    public const RINGS = [20, 50, 70, 90, 100];

    /**
     * One corner's point at a distance from the centre.
     *
     * @return array{0: float, 1: float}
     */
    public static function point(int $corner, int $corners, float $distance, float $cx = self::CX, float $cy = self::CY): array
    {
        $angle = deg2rad(-90 + $corner * 360 / max(1, $corners));

        return [round($cx + $distance * cos($angle), 1), round($cy + $distance * sin($angle), 1)];
    }

    /**
     * The SVG points of a shape: one value (0–100) per corner, in order; a missing value sits at the centre.
     *
     * @param  list<float|int|null>  $values
     */
    public static function polygon(array $values, float $radius = self::RADIUS, float $cx = self::CX, float $cy = self::CY): string
    {
        $corners = count($values);

        return implode(' ', array_map(function (float|int|null $value, int $corner) use ($corners, $radius, $cx, $cy) {
            [$x, $y] = self::point($corner, $corners, max(0, min(100, (float) $value)) / 100 * $radius, $cx, $cy);

            return $x.','.$y;
        }, $values, array_keys($values)));
    }

    /**
     * A ring at one percentage, for a shape of so many corners.
     */
    public static function ring(int $corners, float $pct, float $radius = self::RADIUS, float $cx = self::CX, float $cy = self::CY): string
    {
        return self::polygon(array_fill(0, $corners, $pct), $radius, $cx, $cy);
    }

    /**
     * Where each corner's name goes: its point beyond the edge, and which way the words run.
     *
     * @return array{x: float, y: float, anchor: string, left: float, top: float}
     */
    public static function label(int $corner, int $corners, float $beyond = 30): array
    {
        [$x, $y] = self::point($corner, $corners, self::RADIUS + $beyond);
        $angle = deg2rad(-90 + $corner * 360 / max(1, $corners));
        $cos = cos($angle);

        return [
            'x' => $x,
            'y' => $y,
            'anchor' => $cos > 0.3 ? 'start' : ($cos < -0.3 ? 'end' : 'middle'),
            // As percentages of the frame, for what sits over the drawing in HTML.
            'left' => round($x / self::WIDTH * 100, 2),
            'top' => round($y / self::HEIGHT * 100, 2),
        ];
    }
}
