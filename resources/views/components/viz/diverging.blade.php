{{--
    Bars either side of zero (e.g. operating margin): warm for below, cool for above, a
    neutral midline, and the value written beside each bar.
    Expects $rows = list of ['label' => string, 'value' => float|null] and optionally $unit.
--}}
@props(['rows', 'unit' => '%'])
@php
    $max = max(1, collect($rows)->pluck('value')->filter(fn ($v) => $v !== null)->map(fn ($v) => abs($v))->max() ?? 1);
@endphp
<ul class="flex flex-col gap-xs text-[0.8125rem]">
    @foreach ($rows as $row)
        <li class="grid grid-cols-[minmax(6rem,9rem)_1fr_4rem] items-center gap-sm">
            <span class="truncate">{{ $row['label'] }}</span>
            @if ($row['value'] === null)
                <span class="text-on-surface-variant">Not given</span><span></span>
            @else
                @php($w = abs($row['value']) / $max * 50)
                <span class="relative h-5" title="{{ $row['label'] }}: {{ $row['value'] > 0 ? '+' : '' }}{{ \App\Support\Viz::number($row['value'], 1) }}{{ $unit }}">
                    <span class="absolute inset-y-0 left-1/2 w-px bg-outline" aria-hidden="true"></span>
                    <span class="absolute top-1 h-3 {{ $row['value'] < 0 ? 'bg-viz-2 rounded-l' : 'bg-viz-1 rounded-r' }}"
                          style="{{ $row['value'] < 0 ? 'right: 50%' : 'left: 50%' }}; width: {{ max($w, 0.5) }}%"></span>
                </span>
                <span class="text-right text-[0.8125rem] font-semibold tabular-nums">{{ $row['value'] > 0 ? '+' : '' }}{{ \App\Support\Viz::number($row['value'], 1) }}{{ $unit }}</span>
            @endif
        </li>
    @endforeach
</ul>
