{{-- Category scores (from App\Oha\CategoryRows) with the overall total. --}}
@props(['rows', 'total' => null])
@php($tones = ['excellent' => 'good', 'strong' => 'good', 'developing' => 'warning', 'atrisk' => 'serious', 'critical' => 'critical'])
<div class="overflow-x-auto">
    <table class="w-full text-[0.875rem]">
        <thead class="text-left text-[0.8125rem] uppercase tracking-wider text-on-surface-variant">
            <tr class="border-b-[1.5px] border-outline-variant">
                <th class="py-sm pr-md">Category</th>
                <th class="py-sm pr-md text-right">Points</th>
                <th class="py-sm pr-md w-1/3">Score</th>
                <th class="py-sm">Band</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="border-b border-surface-container">
                    <td class="py-sm pr-md">{{ $row['name'] }}</td>
                    @if ($row['max'] === null)
                        <td class="py-sm pr-md text-right text-on-surface-variant" colspan="3">Not yet weighted</td>
                    @else
                        <td class="py-sm pr-md text-right tabular-nums">{{ rtrim(rtrim(number_format($row['points'], 2), '0'), '.') }} / {{ $row['max'] }}</td>
                        <td class="py-sm pr-md"><x-bar :value="$row['pct']" :label="number_format($row['pct'], 1).'%'" :tone="$tones[$row['band_code']] ?? 'neutral'" /></td>
                        <td class="py-sm"><x-band-chip :code="$row['band_code']" :label="$row['band']" :icon="$row['band_icon']" /></td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        @if ($total)
            <tfoot>
                <tr class="font-bold">
                    <td class="py-sm pr-md">Total</td>
                    <td class="py-sm pr-md text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $total->points_achieved, 2), '0'), '.') }} / {{ $total->points_available }}</td>
                    <td class="py-sm pr-md tabular-nums">{{ number_format((float) $total->pct, 1) }}%</td>
                    <td class="py-sm"><x-band-chip :band="$total->band" /></td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
