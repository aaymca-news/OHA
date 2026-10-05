{{--
    One bar per item against a fixed limit (e.g. the form's 75% line for international
    funding). One series, one colour; the limit is a solid marker named in the header,
    and each value is written beside its bar.
    Expects $rows = list of ['label' => string, 'value' => float|null], $limit and $limitLabel.
--}}
@props(['rows', 'limit', 'limitLabel', 'unit' => '%', 'max' => 100])
<div class="flex flex-col gap-xs">
    <p class="flex items-center gap-xs text-[0.8125rem] text-on-surface-variant">
        <span class="inline-block w-0.5 h-3 bg-band-critical" aria-hidden="true"></span> {{ $limitLabel }}
    </p>
    <ul class="flex flex-col gap-xs text-[0.8125rem]">
        @foreach ($rows as $row)
            <li class="grid grid-cols-[minmax(6rem,9rem)_1fr_3.5rem] items-center gap-sm">
                <span class="truncate">{{ $row['label'] }}</span>
                <span class="relative h-5" title="{{ $row['label'] }}: {{ $row['value'] === null ? 'not given' : \App\Support\Viz::number($row['value'], 1).$unit }}">
                    <span class="absolute inset-y-1 left-0 right-0 rounded bg-surface-container" aria-hidden="true"></span>
                    @if ($row['value'] !== null)
                        <span class="absolute top-1 h-3 left-0 rounded-r {{ $row['value'] > $limit ? 'bg-primary' : 'bg-heat-2' }}" style="width: {{ min(100, $row['value'] / $max * 100) }}%"></span>
                    @endif
                    <span class="absolute -top-0.5 -bottom-0.5 w-0.5 bg-band-critical" style="left: {{ $limit / $max * 100 }}%" aria-hidden="true"></span>
                </span>
                <span class="text-right text-[0.8125rem] font-semibold tabular-nums">{{ $row['value'] === null ? '—' : \App\Support\Viz::number($row['value'], 1).$unit }}</span>
            </li>
        @endforeach
    </ul>
</div>
