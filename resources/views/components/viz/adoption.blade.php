{{--
    The share of movements that have each item (a policy, a practice). One series, one
    colour; "n of m" is written beside every bar.
    Expects $rows from AllianceInsights::adoption().
--}}
@props(['rows', 'labelWidth' => '12rem'])
<ul class="flex flex-col gap-xs text-[0.8125rem]">
    @foreach ($rows as $row)
        <li class="grid items-center gap-sm" style="grid-template-columns: minmax(7rem, {{ $labelWidth }}) 1fr 4.5rem">
            <span class="truncate" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
            <span class="relative h-3 rounded bg-surface-container" title="{{ $row['label'] }}: {{ $row['yes'] }} of {{ $row['answered'] }} movements">
                <span class="absolute inset-y-0 left-0 rounded-r bg-primary-container" style="width: {{ $row['pct'] }}%"></span>
            </span>
            <span class="text-right tabular-nums"><span class="font-semibold">{{ round($row['pct']) }}%</span> <span class="text-[0.8125rem] text-on-surface-variant">{{ $row['yes'] }}/{{ $row['answered'] }}</span></span>
        </li>
    @endforeach
</ul>
