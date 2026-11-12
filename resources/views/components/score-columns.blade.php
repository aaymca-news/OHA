{{--
    Category scores side by side, oldest on the left, each with its change in percentage
    points since the column before: across a movement's assessments, or across one cycle's
    baselines. Percentages are compared, not points, since a category's maximum may change
    between forms. The total row comes last; optional extra rows (ODP tasks done, evidence)
    follow it. Also the table view of the growth curve.
      columns  list of ['label' => string, 'href' => string|null, 'rows' => code => CategoryRows row,
               'pct' => float, 'band' => HealthBand|null]
      extra    list of ['label' => string, 'values' => list of string, one per column]
--}}
@props(['columns', 'extra' => []])
@php
    $columns = array_values(collect($columns)->all());
    $first = $columns[0]['rows'] ?? [];
    $change = function (?float $now, ?float $before): ?array {
        if ($now === null || $before === null) {
            return null;
        }
        $delta = round($now - $before, 1);

        return match (true) {
            $delta > 0 => ['+'.number_format($delta, 1), 'arrow_upward', 'text-good-ink', 'up'],
            $delta < 0 => [number_format($delta, 1), 'arrow_downward', 'text-critical-ink', 'down'],
            default => ['0.0', 'arrow_forward', 'text-on-surface-variant', 'no change'],
        };
    };
@endphp
<x-table bare {{ $attributes }}>
    <x-slot:head>
        <tr>
            <th scope="col">Category</th>
            @foreach ($columns as $column)
                <th scope="col" class="text-right whitespace-nowrap">
                    @if ($column['href'] ?? null)
                        <a href="{{ $column['href'] }}" class="text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface normal-case tracking-normal">{{ $column['label'] }}</a>
                    @else
                        <span class="normal-case tracking-normal">{{ $column['label'] }}</span>
                    @endif
                </th>
            @endforeach
        </tr>
    </x-slot:head>
    @foreach ($first as $code => $row)
        <tr class="border-b border-surface-container">
            <th scope="row" class="text-left font-normal">{{ $row['name'] }}</th>
            @foreach ($columns as $i => $column)
                @php
                    $cell = $column['rows'][$code] ?? null;
                    $moved = $i > 0 ? $change($cell['pct'] ?? null, $columns[$i - 1]['rows'][$code]['pct'] ?? null) : null;
                @endphp
                <td class="text-right tabular-nums whitespace-nowrap">
                    @if (($cell['pct'] ?? null) === null)
                        <span class="text-on-surface-variant">Not weighted</span>
                    @else
                        <span class="font-semibold">{{ number_format($cell['pct'], 1) }}%</span>
                        @if ($moved)
                            <span class="inline-flex items-center text-[0.8125rem] {{ $moved[2] }}">
                                <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">{{ $moved[1] }}</span>{{ $moved[0] }}<span class="sr-only"> ({{ $moved[3] }})</span>
                            </span>
                        @endif
                    @endif
                </td>
            @endforeach
        </tr>
    @endforeach
    {{-- The total row: last in the body (x-table has no footer slot). --}}
    <tr class="font-bold">
        <th scope="row" class="text-left">Total</th>
        @foreach ($columns as $i => $column)
            @php
                $moved = $i > 0 ? $change((float) $column['pct'], (float) $columns[$i - 1]['pct']) : null;
            @endphp
            <td class="text-right tabular-nums whitespace-nowrap">
                {{ number_format((float) $column['pct'], 1) }}%
                @if ($moved)
                    <span class="inline-flex items-center text-[0.8125rem] {{ $moved[2] }}">
                        <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">{{ $moved[1] }}</span>{{ $moved[0] }}<span class="sr-only"> ({{ $moved[3] }})</span>
                    </span>
                @endif
                @if ($column['band'] ?? null)
                    <span class="block font-normal mt-1"><x-status-pill :tone="\App\Support\Bands::tone($column['band']->code)" class="!py-[2px] !px-[8px] !text-[0.71875rem]">{{ $column['band']->label }}</x-status-pill></span>
                @endif
            </td>
        @endforeach
    </tr>
    @foreach ($extra as $line)
        <tr class="border-t border-surface-container text-[0.84375rem]">
            <th scope="row" class="text-left font-normal text-on-surface-variant">{{ $line['label'] }}</th>
            @foreach ($line['values'] as $value)
                <td class="text-right tabular-nums whitespace-nowrap text-on-surface-variant">{{ $value }}</td>
            @endforeach
        </tr>
    @endforeach
</x-table>
