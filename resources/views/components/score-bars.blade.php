{{--
    Scores as bars (design standard of 10 Nov 2026): one per row, coloured by its health
    band, with the score and the band beside it. Faint lines mark where Developing and
    Strong begin. Used for a movement's categories and for every movement in the region.
    Expects $rows as list of ['label', 'pct', 'band_code', 'band', 'href' => null, 'title' => null],
    already in the order to show.
--}}
@use('App\Support\Bands')
@props(['rows', 'labelWidth' => '13rem'])
@php
    $marks = array_values(array_filter([Bands::from('developing'), Bands::from('strong')]));
@endphp
<div {{ $attributes->class('flex flex-col gap-md') }}>
    <ul class="flex flex-col gap-[12px]">
        @foreach ($rows as $row)
            <li class="grid grid-cols-[minmax(0,1fr)_auto] sm:grid-cols-[minmax(8rem,var(--label-w))_1fr_auto] items-center gap-x-[14px] gap-y-[6px] text-[0.875rem]" style="--label-w: {{ $labelWidth }}">
                @if ($row['href'] ?? null)
                    <a href="{{ $row['href'] }}" class="truncate text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface" title="{{ $row['label'] }}">{{ $row['label'] }}</a>
                @else
                    <span class="truncate" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                @endif
                <span class="relative h-2.5 rounded-full bg-[#f0eeea] col-span-2 sm:col-span-1 row-start-2 sm:row-start-auto" @if ($row['title'] ?? null) title="{{ $row['title'] }}" @endif>
                    <span class="absolute inset-y-0 left-0 rounded-full {{ Bands::fill($row['band_code']) }}" style="width: {{ max(1.5, min(100, (float) $row['pct'])) }}%"></span>
                    @foreach ($marks as $mark)
                        <span class="absolute -top-[3px] -bottom-[3px] w-px bg-[#d3d0c9]" style="left: {{ $mark }}%" aria-hidden="true"></span>
                    @endforeach
                </span>
                <span class="flex items-center justify-end gap-sm sm:min-w-[8.5rem]">
                    <span class="font-bold tabular-nums">{{ \App\Support\Viz::pct($row['pct']) }}</span>
                    <x-status-pill :tone="Bands::tone($row['band_code'])" class="!py-[2px] !px-[8px] !text-[0.71875rem]">{{ $row['band'] }}</x-status-pill>
                </span>
            </li>
        @endforeach
    </ul>
    <p class="flex flex-wrap gap-x-md gap-y-xs text-[0.78125rem] text-on-surface-variant">
        @if (count($marks) === 2)
            <span class="inline-flex items-center gap-[6px]"><span class="w-px h-3 bg-[#b8b4ac]" aria-hidden="true"></span>{{ (int) $marks[0] }}% Developing · {{ (int) $marks[1] }}% Strong</span>
        @endif
        {{ $slot }}
    </p>
</div>
