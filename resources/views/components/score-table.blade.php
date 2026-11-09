{{-- Category scores (from App\Oha\CategoryRows) with the overall total. --}}
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
        <tr class="border-b border-surface-container">
            <td>{{ $row['name'] }}</td>
            @if ($row['max'] === null)
                <td class="text-right text-on-surface-variant" colspan="3">Not yet weighted</td>
            @else
                <td class="text-right tabular-nums">{{ \App\Support\Viz::figure($row['points']) }} / {{ $row['max'] }}</td>
                <td><x-bar :value="$row['pct']" :label="\App\Support\Viz::pct($row['pct'])" :tone="\App\Enums\BandCode::toneOf($row['band_code'])" /></td>
                <td><x-band-chip :code="$row['band_code']" :label="$row['band']" :icon="$row['band_icon']" /></td>
            @endif
        </tr>
    @endforeach
    @if ($total)
        {{-- The total row: last in the body (x-table has no footer slot). --}}
        <tr class="font-bold">
            <td>Total</td>
            <td class="text-right tabular-nums">{{ \App\Support\Viz::figure((float) $total->points_achieved) }} / {{ $total->points_available }}</td>
            <td class="tabular-nums">{{ number_format((float) $total->pct, 1) }}%</td>
            <td><x-band-chip :band="$total->band" /></td>
        </tr>
    @endif
</x-table>
