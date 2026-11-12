{{--
    Each movement's journey in its latest cycle, one row each: Baseline 1 and its score now
    on one 0–100% track (hollow: Baseline 1; filled: now), the change in percentage points,
    its band then and now, and the follow-ups closed. The legend names the two marks; the
    figures are written beside them. Expects $rows (from Journeys::alliance) and $limit (or null).
--}}
@php
    $shown = $limit !== null ? array_slice($rows, 0, $limit) : $rows;
@endphp
<div class="flex flex-wrap items-center gap-md text-[0.8125rem] text-on-surface-variant" aria-hidden="true">
    <span class="inline-flex items-center gap-xs"><span class="size-[10px] rounded-full border-2 border-on-surface bg-white"></span>Baseline 1</span>
    <span class="inline-flex items-center gap-xs"><span class="size-[10px] rounded-full bg-on-surface"></span>Now (newest baseline)</span>
</div>
<x-table bare>
    <x-slot:head>
        <tr><th scope="col">Movement</th><th scope="col" class="w-[38%]">Baseline 1 → now</th><th scope="col" class="text-right">Change</th><th scope="col">Band</th><th scope="col">Follow-ups closed</th></tr>
    </x-slot:head>
    @foreach ($shown as $row)
        @php
            [$from, $to] = [$row['first']['pct'], $row['now']['pct']];
            [$low, $high] = [min($from, $to), max($from, $to)];
        @endphp
        <tr class="border-t border-surface-container align-middle">
            <th scope="row" class="text-left font-normal">
                <a href="{{ route('movements.show', $row['movement']) }}" class="font-semibold text-on-surface hover:underline">{{ $row['movement']->name }}</a>
                <span class="block text-[0.8125rem] text-on-surface-variant">{{ $row['assessment']->period_label }}</span>
            </th>
            <td>
                <span class="relative block h-5" role="img"
                      aria-label="Baseline 1: {{ \App\Support\Viz::pct($from) }}; now, Baseline {{ $row['now']['number'] }}: {{ \App\Support\Viz::pct($to) }}"
                      title="Baseline 1: {{ \App\Support\Viz::pct($from) }} · Baseline {{ $row['now']['number'] }}: {{ \App\Support\Viz::pct($to) }}">
                    <span class="absolute inset-x-0 top-1/2 border-t border-[#efede8]" aria-hidden="true"></span>
                    @if ($high > $low)
                        <span class="absolute top-1/2 -translate-y-1/2 h-[2px] bg-on-surface" style="left: {{ $low }}%; width: {{ $high - $low }}%" aria-hidden="true"></span>
                    @endif
                    <span class="absolute top-1/2 size-[10px] -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-on-surface bg-white shadow-[0_0_0_2px_#ffffff]" style="left: {{ $from }}%" aria-hidden="true"></span>
                    <span class="absolute top-1/2 size-[10px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-on-surface shadow-[0_0_0_2px_#ffffff]" style="left: {{ $to }}%" aria-hidden="true"></span>
                </span>
                <span class="block text-[0.78125rem] text-on-surface-variant tabular-nums">{{ \App\Support\Viz::pct($from) }} → <span class="font-semibold text-on-surface">{{ \App\Support\Viz::pct($to) }}</span></span>
            </td>
            <td class="text-right tabular-nums whitespace-nowrap font-semibold {{ $row['change'] > 0 ? 'text-good-ink' : ($row['change'] < 0 ? 'text-critical-ink' : 'text-on-surface-variant') }}">
                {{ $row['follow_ups'] === 0 ? '—' : ($row['change'] > 0 ? '+' : '').number_format($row['change'], 1) }}
            </td>
            <td class="whitespace-nowrap">
                @if ($row['band_moved'] !== 0 && $row['first']['band'])
                    <span class="text-[0.8125rem] text-on-surface-variant">{{ $row['first']['band']->label }} →</span>
                @endif
                @if ($row['now']['band'])
                    <x-status-pill :tone="\App\Support\Bands::tone($row['now']['band']->code)">{{ $row['now']['band']->label }}</x-status-pill>
                @endif
            </td>
            <td class="tabular-nums">{{ $row['follow_ups'] }} of {{ \App\Queries\Journeys::slots() - 1 }}</td>
        </tr>
    @endforeach
</x-table>
