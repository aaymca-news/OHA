{{--
    Category scores across the movement's scored assessments, oldest on the left, each with
    its change in percentage points since the one before. Percentages are compared, not
    points, since a category's maximum may change between forms. Expects $history.
--}}
@php
    $first = $history->first()['rows'];
    $change = function (?float $now, ?float $before): ?array {
        if ($now === null || $before === null) {
            return null;
        }
        $delta = round($now - $before, 1);

        return match (true) {
            $delta > 0 => ['+'.number_format($delta, 1), 'arrow_upward', 'text-good-ink', 'up'],
            $delta < 0 => [number_format($delta, 1), 'arrow_downward', 'text-error', 'down'],
            default => ['0.0', 'arrow_forward', 'text-on-surface-variant', 'no change'],
        };
    };
@endphp
<x-card title="Scores over time" :subtitle="'Each category’s score in the last '.$history->count().' approved assessments, with the change since the one before, in percentage points.'">
    <div class="relative overflow-x-auto">
        <table class="w-full text-[0.875rem]">
            <thead class="text-left text-[0.8125rem] uppercase tracking-wider text-on-surface-variant">
                <tr class="border-b-[1.5px] border-outline-variant">
                    <th scope="col" class="py-sm pr-md">Category</th>
                    @foreach ($history as $column)
                        <th scope="col" class="py-sm pr-md text-right whitespace-nowrap">
                            <a href="{{ route('assessments.show', $column['assessment']) }}" class="text-primary underline normal-case tracking-normal">{{ $column['assessment']->period_label }}</a>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($first as $code => $row)
                    <tr class="border-b border-surface-container">
                        <th scope="row" class="py-sm pr-md text-left font-normal">{{ $row['name'] }}</th>
                        @foreach ($history as $i => $column)
                            @php($cell = $column['rows'][$code] ?? null)
                            @php($moved = $i > 0 ? $change($cell['pct'] ?? null, $history[$i - 1]['rows'][$code]['pct'] ?? null) : null)
                            <td class="py-sm pr-md text-right tabular-nums whitespace-nowrap">
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
            </tbody>
            <tfoot>
                <tr class="font-bold">
                    <th scope="row" class="py-sm pr-md text-left">Total</th>
                    @foreach ($history as $i => $column)
                        @php($total = $column['assessment']->score)
                        @php($moved = $i > 0 ? $change((float) $total->pct, (float) $history[$i - 1]['assessment']->score->pct) : null)
                        <td class="py-sm pr-md text-right tabular-nums whitespace-nowrap">
                            {{ number_format((float) $total->pct, 1) }}%
                            @if ($moved)
                                <span class="inline-flex items-center text-[0.8125rem] {{ $moved[2] }}">
                                    <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">{{ $moved[1] }}</span>{{ $moved[0] }}<span class="sr-only"> ({{ $moved[3] }})</span>
                                </span>
                            @endif
                            <span class="block font-normal"><x-band-chip :band="$total->band" /></span>
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>
</x-card>
