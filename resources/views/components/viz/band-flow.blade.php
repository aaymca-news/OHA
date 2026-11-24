{{--
    Band movement (decided 23 Nov 2026): the movements with a baseline, in their band at the
    baseline (left) and now (right), joined by a ribbon per pair of bands, as wide as the
    movements in it: green when they moved up, red when down, grey when they stayed.
      bands  list of ['band' => HealthBand, 'baseline' => int, 'now' => int], best band first
      flows  list of ['from' => band code, 'to' => band code, 'count' => int]
--}}
@use('App\Support\Viz')
@props(['bands', 'flows'])
@php
    $bands = collect($bands)->values();
    $order = $bands->pluck('band.code')->flip();
    $total = max(1, $bands->sum('baseline'));
    [$top, $gap, $height] = [28, 12, 300];
    $unit = ($height - $top - 8 - $gap * max(0, $bands->count() - 1)) / $total;
    $layout = function (string $key) use ($bands, $top, $gap, $unit) {
        $y = $top;

        return $bands->map(function (array $row) use (&$y, $key, $gap, $unit) {
            $h = max(2, $row[$key] * $unit);
            $node = ['y' => $y, 'h' => $h, 'count' => $row[$key], 'band' => $row['band']];
            $y += $h + $gap;

            return $node;
        })->all();
    };
    $left = $layout('baseline');
    $right = $layout('now');
    $out = array_column($left, 'y');
    $in = array_column($right, 'y');
    $ribbons = [];
    foreach (collect($flows)->sortBy(fn ($f) => [$order[$f['from']] ?? 99, $order[$f['to']] ?? 99]) as $flow) {
        [$i, $j] = [$order[$flow['from']] ?? null, $order[$flow['to']] ?? null];
        if ($i === null || $j === null) {
            continue;
        }
        $h = $flow['count'] * $unit;
        [$y1, $y2] = [$out[$i], $in[$j]];
        $out[$i] += $h;
        $in[$j] += $h;
        $r = fn (float $v) => round($v, 1);
        $ribbons[] = [
            'd' => "M128 {$r($y1)} C280 {$r($y1)} 280 {$r($y2)} 432 {$r($y2)} L432 {$r($y2 + $h)} C280 {$r($y2 + $h)} 280 {$r($y1 + $h)} 128 {$r($y1 + $h)} Z",
            'fill' => $j < $i ? 'var(--color-good-ink)' : ($j > $i ? 'var(--color-critical-ink)' : 'var(--color-outline)'),
        ];
    }
    $summary = $bands->map(fn ($row) => $row['band']->label.': '.$row['baseline'].' at baseline, '.$row['now'].' now')->join('; ');
@endphp
<figure {{ $attributes->class('flex flex-col gap-sm') }}>
    <svg viewBox="0 0 560 {{ $height }}" class="w-full h-auto" role="img" aria-label="Bands at baseline and now. {{ $summary }}">
        <text x="121" y="14" text-anchor="middle" font-size="12" font-weight="600" fill="var(--color-on-surface-variant)">AT BASELINE</text>
        <text x="439" y="14" text-anchor="middle" font-size="12" font-weight="600" fill="var(--color-on-surface-variant)">NOW</text>
        @foreach ($ribbons as $ribbon)
            <path d="{{ $ribbon['d'] }}" fill="{{ $ribbon['fill'] }}" fill-opacity="0.28" />
        @endforeach
        @foreach ([[$left, 114, 104, 'end'], [$right, 432, 456, 'start']] as [$nodes, $nx, $tx, $anchor])
            @foreach ($nodes as $node)
                <rect x="{{ $nx }}" y="{{ round($node['y'], 1) }}" width="14" height="{{ round($node['h'], 1) }}" rx="3" fill="{{ $node['count'] ? Viz::bandStroke($node['band']->code) : '#e4e1db' }}" />
                <text x="{{ $tx }}" y="{{ round($node['y'] + $node['h'] / 2 + 4, 1) }}" text-anchor="{{ $anchor }}" font-size="13" fill="var(--color-on-surface)">{{ $anchor === 'end' ? $node['band']->label.' '.$node['count'] : $node['count'].' '.$node['band']->label }}</text>
            @endforeach
        @endforeach
    </svg>
    <figcaption class="flex flex-wrap gap-x-md gap-y-xs text-[0.8125rem] text-on-surface-variant">
        <span class="inline-flex items-center gap-xs"><span class="w-4 h-2 rounded-sm bg-good-ink/40" aria-hidden="true"></span>Moved up a band</span>
        <span class="inline-flex items-center gap-xs"><span class="w-4 h-2 rounded-sm bg-outline/40" aria-hidden="true"></span>Same band</span>
        <span class="inline-flex items-center gap-xs"><span class="w-4 h-2 rounded-sm bg-critical-ink/40" aria-hidden="true"></span>Moved down</span>
    </figcaption>
</figure>
