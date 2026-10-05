{{--
    Every rated movement against every category: one hue, lighter to darker as the score
    rises, stepped at the band thresholds. Each cell also prints its percentage, and the
    legend names the bands, so the grid reads without relying on colour.
    Expects $heatmap = ['categories' => Collection<Category>, 'rows' => list<array{movement, status, cells}>].
--}}
@props(['heatmap'])
@php
    $short = [
        'financial' => 'Financial', 'governance' => 'Governance', 'constitution' => 'Constitution', 'me' => 'M&E',
        'strategy' => 'Strategy', 'diversity' => 'Diversity & youth', 'comms' => 'Comms', 'staff' => 'Staff & volunteers',
    ];
@endphp
<div class="flex flex-col gap-sm">
    <div class="overflow-x-auto -mx-xs px-xs">
        <table class="w-full min-w-[40rem] text-[0.8125rem] border-separate border-spacing-[2px]">
            <thead>
                <tr>
                    <th class="text-left font-semibold text-on-surface-variant px-xs py-1 sticky left-0 bg-surface-container-lowest">Movement</th>
                    @foreach ($heatmap['categories'] as $category)
                        <th class="font-semibold text-on-surface-variant px-xs py-1 align-bottom" title="{{ $category->name }} (out of {{ $category->max_points }})">{{ $short[$category->code] ?? $category->name }}</th>
                    @endforeach
                    <th class="font-semibold text-on-surface-variant px-xs py-1">Overall</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($heatmap['rows'] as $row)
                    <tr>
                        <th scope="row" class="text-left font-normal px-xs py-1 sticky left-0 bg-surface-container-lowest whitespace-nowrap">
                            <a href="{{ route('movements.show', $row['movement']) }}" class="text-primary underline">{{ str_replace(' YMCA', '', $row['movement']->name) }}</a>
                        </th>
                        @foreach ($heatmap['categories'] as $category)
                            @php($value = $row['cells'][$category->code] ?? null)
                            @php($heat = \App\Support\Viz::heat($value))
                            <td class="{{ $heat['fill'] }} {{ $heat['text'] }} text-center tabular-nums rounded py-1.5 min-w-12"
                                title="{{ $row['movement']->name }} · {{ $category->name }}: {{ $value === null ? 'not rated' : number_format($value, 0).'% ('.$heat['band'].')' }}">
                                {{ $value === null ? '—' : round($value) }}
                            </td>
                        @endforeach
                        @php($overall = \App\Support\Viz::heat((float) $row['status']->pct))
                        <td class="{{ $overall['fill'] }} {{ $overall['text'] }} text-center font-bold tabular-nums rounded py-1.5 min-w-12"
                            title="{{ $row['movement']->name }} overall: {{ number_format((float) $row['status']->pct, 1) }}% ({{ $overall['band'] }})">
                            {{ round((float) $row['status']->pct) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <ul class="flex flex-wrap gap-x-md gap-y-xs text-[0.8125rem] text-on-surface-variant" aria-label="Legend">
        @foreach (\App\Support\Viz::legend() as $step)
            <li class="flex items-center gap-xs"><span class="w-3 h-3 rounded-sm {{ \App\Support\Viz::heat($step['sample'])['fill'] }}" aria-hidden="true"></span>{{ $step['label'] }}</li>
        @endforeach
    </ul>
</div>
