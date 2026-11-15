{{--
    Every rated movement against every area, with a switch (decided 15 Nov 2026): its
    current score (the brand's greys, darker as the score rises, stepped at the band limits)
    or its change since the baseline (one blue, darker as the gain grows; a warm wash for a
    fall; grey for none or no follow-up yet). Each cell prints its value and the legend names
    the steps, so the grid reads without relying on colour. Areas go by their short name,
    the full one on hover.
      categories  Collection<Category> (weighted, in form order)
      rows        list of ['movement', 'pct', 'change', 'cells' => code => pct|null, 'gains' => code => points|null]
--}}
@use('App\Support\Viz')
@props(['categories', 'rows'])
@php
    $cell = 'text-center tabular-nums rounded py-1.5 min-w-12';
    $head = 'font-semibold text-on-surface-variant px-xs py-1 align-bottom';
    $steps = Viz::GAIN_STEPS;
@endphp
<div x-data="{ mode: 'score' }" class="flex flex-col gap-sm">
    <div class="flex">
        <div class="flex gap-xs rounded-full bg-[#f2f1ee] p-[3px] text-[0.8125rem]" role="group" aria-label="Show">
            @foreach (['score' => 'Current score', 'change' => 'Change since baseline'] as $mode => $text)
                <button type="button" x-on:click="mode = '{{ $mode }}'" x-bind:aria-pressed="(mode === '{{ $mode }}').toString()"
                        x-bind:class="mode === '{{ $mode }}' ? 'bg-surface-container-lowest text-on-surface shadow-[0_1px_2px_rgba(20,20,20,0.08)]' : 'text-on-surface-variant hover:text-on-surface'"
                        class="min-h-8 px-[12px] rounded-full font-semibold">{{ $text }}</button>
            @endforeach
        </div>
    </div>
    <div class="relative overflow-x-auto -mx-xs px-xs">
        <table class="w-full min-w-[40rem] text-[0.8125rem] border-separate border-spacing-[2px]">
            <thead>
                <tr>
                    <th class="text-left {{ $head }} sticky left-0 bg-surface-container-lowest">Movement</th>
                    @foreach ($categories as $category)
                        <th class="{{ $head }}" title="{{ $category->name }} (out of {{ $category->max_points }})">{{ $category->short_name }}</th>
                    @endforeach
                    <th class="{{ $head }}">Overall</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <th scope="row" class="text-left font-normal px-xs py-1 sticky left-0 bg-surface-container-lowest whitespace-nowrap">
                            <a href="{{ route('movements.show', $row['movement']) }}" class="text-primary underline">{{ str_replace(' YMCA', '', $row['movement']->name) }}</a>
                        </th>
                        @foreach ($categories as $category)
                            @php($value = $row['cells'][$category->code] ?? null)
                            @php($gain = $row['gains'][$category->code] ?? null)
                            @php($heat = Viz::heat($value))
                            @php($step = Viz::gain($gain))
                            <td class="{{ $cell }} {{ $heat['fill'] }} {{ $heat['text'] }}" x-show="mode === 'score'"
                                title="{{ $row['movement']->name }} · {{ $category->name }}: {{ $value === null ? 'not rated' : Viz::figure(round($value)).'% ('.$heat['band'].')' }}">{{ $value === null ? '—' : round($value) }}</td>
                            <td class="{{ $cell }} {{ $step['fill'] }} {{ $step['text'] }}" x-show="mode === 'change'" x-cloak
                                title="{{ $row['movement']->name }} · {{ $category->name }}: {{ $gain === null ? 'no follow-up yet' : Viz::signed($gain).' points since baseline' }}">{{ $gain === null ? '—' : Viz::signed($gain, 0) }}</td>
                        @endforeach
                        @php($overall = Viz::heat($row['pct']))
                        @php($overallGain = Viz::gain($row['change']))
                        <td class="{{ $cell }} font-bold {{ $overall['fill'] }} {{ $overall['text'] }}" x-show="mode === 'score'"
                            title="{{ $row['movement']->name }} overall: {{ Viz::pct($row['pct']) }} ({{ $overall['band'] }})">{{ round($row['pct']) }}</td>
                        <td class="{{ $cell }} font-bold {{ $overallGain['fill'] }} {{ $overallGain['text'] }}" x-show="mode === 'change'" x-cloak
                            title="{{ $row['movement']->name }} overall: {{ $row['change'] === null ? 'no follow-up yet' : Viz::signed($row['change']).' points since baseline' }}">{{ $row['change'] === null ? '—' : Viz::signed($row['change']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <ul class="flex flex-wrap gap-x-md gap-y-xs text-[0.8125rem] text-on-surface-variant" aria-label="Legend" x-show="mode === 'score'">
        @foreach (Viz::legend() as $legend)
            <li class="flex items-center gap-xs"><span class="w-3 h-3 rounded-sm {{ Viz::heat($legend['sample'])['fill'] }}" aria-hidden="true"></span>{{ $legend['label'] }}</li>
        @endforeach
    </ul>
    <ul class="flex flex-wrap gap-x-md gap-y-xs text-[0.8125rem] text-on-surface-variant" aria-label="Legend" x-show="mode === 'change'" x-cloak>
        @foreach ([[null, 'No change, or no follow-up yet'], [$steps[0], 'Up to +'.$steps[0]], [$steps[1], '+'.$steps[0].' to +'.$steps[1]], [$steps[1] + 1, 'Over +'.$steps[1]], [-1, 'Down']] as [$sample, $text])
            <li class="flex items-center gap-xs"><span class="w-3 h-3 rounded-sm {{ Viz::gain($sample)['fill'] }}" aria-hidden="true"></span>{{ $text }}</li>
        @endforeach
    </ul>
</div>
