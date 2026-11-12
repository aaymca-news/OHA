{{--
    Category scores (from App\Oha\CategoryRows) with the overall total, in the site's newer
    style (design standard of 10 Nov 2026): a slim bar in the band's colour, the score, and
    the band as a status pill.
--}}
@use('App\Support\Bands')
@props(['rows', 'total' => null])
<x-table bare>
    <x-slot:head>
        <tr>
            <th>Category</th>
            <th class="text-right">Points</th>
            <th class="w-1/3">Score</th>
            <th>Band</th>
        </tr>
    </x-slot:head>
    @foreach ($rows as $row)
        <tr class="border-b border-[#f2f1ec]">
            <td>{{ $row['name'] }}</td>
            @if ($row['max'] === null)
                <td class="text-right text-on-surface-variant" colspan="3">Not yet weighted</td>
            @else
                <td class="text-right tabular-nums">{{ \App\Support\Viz::figure($row['points']) }} / {{ $row['max'] }}</td>
                <td>
                    <span class="flex items-center gap-sm">
                        <span class="relative h-2 flex-1 min-w-[5rem] rounded-full bg-[#f0eeea]" aria-hidden="true">
                            <span class="absolute inset-y-0 left-0 rounded-full {{ Bands::fill($row['band_code']) }}" style="width: {{ max(1.5, min(100, (float) $row['pct'])) }}%"></span>
                        </span>
                        <span class="w-[3.5rem] text-right font-semibold tabular-nums">{{ \App\Support\Viz::pct($row['pct']) }}</span>
                    </span>
                </td>
                <td><x-status-pill :tone="Bands::tone($row['band_code'])">{{ $row['band'] }}</x-status-pill></td>
            @endif
        </tr>
    @endforeach
    @if ($total)
        {{-- The total row: last in the body (x-table has no footer slot). --}}
        <tr class="font-bold">
            <td>Total</td>
            <td class="text-right tabular-nums">{{ \App\Support\Viz::figure((float) $total->points_achieved) }} / {{ $total->points_available }}</td>
            <td class="tabular-nums text-right pr-md">{{ number_format((float) $total->pct, 1) }}%</td>
            <td><x-status-pill :tone="Bands::tone($total->band->code)">{{ $total->band->label }}</x-status-pill></td>
        </tr>
    @endif
</x-table>
