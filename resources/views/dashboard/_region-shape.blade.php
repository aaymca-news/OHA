{{--
    The region's health shape (decided 23 Nov 2026): the scored areas as an octagon, in three
    views: the region's medians at the baseline and now; now, zone by zone (all zones only);
    or up to three movements side by side. The table beside it holds every value drawn, and
    hovering a corner picks out its row. Expects $data (App\Queries\DashboardAnalytics::region)
    and $zone.
--}}
@use('App\Support\Radar')
@php
    $shape = $data['shape'];
    $areas = $shape['areas'];
    $zoneColors = ['#7a5cc2', '#2f6fb0', '#2e9e83', '#d9822b', '#8c3d18', '#4f4e4a'];
    $pickColors = ['#2f6fb0', '#d9822b', '#7a5cc2'];
    $movements = collect($shape['movements']);
    // Three to start with: the strongest, one from the middle and the weakest.
    $start = $movements->count() <= 3 ? $movements->pluck('id')->all()
        : [$movements->first()['id'], $movements[intdiv($movements->count(), 2)]['id'], $movements->last()['id']];
    $byZone = $zone === null && count($shape['zones']) > 1;
    $pct = fn (?float $v) => $v === null ? '—' : \App\Support\Viz::pct($v, 0);
    $th = 'text-[0.71875rem] font-semibold uppercase tracking-[0.05em] text-on-surface-variant pb-sm px-[8px]';
    $td = 'px-[8px] py-[7px] border-t border-[#f0eeea] tabular-nums';
@endphp
<x-panel title="The region’s health shape" info="area_score" subtitle="The 8 scored areas as an octagon. Rings at 20, 50, 70 and 90%: where the bands begin."
         x-data="{ mode: 'region', axis: null, picked: {{ \Illuminate\Support\Js::from($start) }}, colors: {{ \Illuminate\Support\Js::from($pickColors) }},
                   names: {{ \Illuminate\Support\Js::from($movements->pluck('name', 'id')) }}, values: {{ \Illuminate\Support\Js::from($movements->pluck('values', 'id')) }},
                   toggle(id) { this.picked = this.picked.includes(id) ? this.picked.filter(p => p !== id) : this.picked.concat([id]).slice(0, 3) },
                   cell(v) { return v === null ? '—' : Math.round(v) + '%' } }">
    <x-slot:actions>
        <div class="flex flex-wrap gap-xs rounded-full bg-[#f2f1ee] p-[3px] text-[0.8125rem]" role="group" aria-label="Show">
            @foreach (array_filter(['region' => 'Region: baseline vs now', 'zones' => $byZone ? 'By zone' : null, 'compare' => 'Compare movements']) as $key => $text)
                <button type="button" x-on:click="mode = '{{ $key }}'" x-bind:aria-pressed="(mode === '{{ $key }}').toString()"
                        x-bind:class="mode === '{{ $key }}' ? 'bg-surface-container-lowest text-on-surface shadow-[0_1px_2px_rgba(20,20,20,0.08)]' : 'text-on-surface-variant hover:text-on-surface'"
                        class="min-h-8 px-[12px] rounded-full font-semibold">{{ $text }}</button>
            @endforeach
        </div>
    </x-slot:actions>

    <div x-show="mode === 'compare'" x-cloak class="flex flex-wrap gap-xs mb-md" role="group" aria-label="Movements to compare, up to three">
        @foreach ($movements as $m)
            <button type="button" x-on:click="toggle({{ $m['id'] }})" x-bind:aria-pressed="picked.includes({{ $m['id'] }}).toString()"
                    x-bind:disabled="picked.length >= 3 ? ! picked.includes({{ $m['id'] }}) : false"
                    x-bind:class="picked.includes({{ $m['id'] }}) ? 'border-on-surface' : 'border-[#e7e5e0]'"
                    class="inline-flex items-center gap-[6px] min-h-8 px-[12px] rounded-full border bg-surface-container-lowest text-[0.8125rem] font-semibold disabled:opacity-45 disabled:cursor-not-allowed">
                <span class="size-2.5 rounded-full" x-bind:style="'background:' + (picked.includes({{ $m['id'] }}) ? colors[picked.indexOf({{ $m['id'] }})] : '#e4e1db')" aria-hidden="true"></span>{{ $m['name'] }}
            </button>
        @endforeach
    </div>

    <div class="grid gap-lg lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] items-center">
        <x-viz.radar :areas="$areas" label="The region’s health shape: each area’s median now and at the baseline, by zone, or for the movements picked; the table beside it holds every value.">
            <g x-show="mode === 'region'">
                <polygon points="{{ Radar::polygon($shape['baseline']) }}" fill="none" stroke="#d9822b" stroke-width="2" stroke-dasharray="6 4" stroke-linejoin="round" />
                <polygon points="{{ Radar::polygon($shape['now']) }}" fill="#2f6fb0" fill-opacity="0.16" stroke="#2f6fb0" stroke-width="2.2" stroke-linejoin="round" />
                @foreach ($shape['now'] as $k => $v)
                    @php([$x, $y] = Radar::point($k, count($areas), max(0, (float) $v) / 100 * Radar::RADIUS))
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" fill="#2f6fb0" />
                @endforeach
            </g>
            @if ($byZone)
                <g x-show="mode === 'zones'" x-cloak>
                    @foreach ($shape['zones'] as $i => $z)
                        <polygon points="{{ Radar::polygon($z['values']) }}" fill="{{ $zoneColors[$i % 6] }}" fill-opacity="0.05" stroke="{{ $zoneColors[$i % 6] }}" stroke-width="2" stroke-linejoin="round" />
                    @endforeach
                </g>
            @endif
            <g x-show="mode === 'compare'" x-cloak>
                @foreach ($movements as $m)
                    <polygon points="{{ Radar::polygon($m['values']) }}" x-show="picked.includes({{ $m['id'] }})"
                             x-bind:stroke="colors[picked.indexOf({{ $m['id'] }})]" x-bind:fill="colors[picked.indexOf({{ $m['id'] }})]"
                             fill-opacity="0.1" stroke-width="2" stroke-linejoin="round" />
                @endforeach
            </g>
        </x-viz.radar>

        <div class="min-w-0">
            {{-- The region, at the baseline and now --}}
            <div x-show="mode === 'region'">
                <p class="flex flex-wrap gap-x-md gap-y-xs mb-sm text-[0.8125rem] text-on-surface-variant">
                    <span class="inline-flex items-center gap-xs"><span class="w-[18px] border-t-[3px] border-[#2f6fb0]" aria-hidden="true"></span>Median now</span>
                    <span class="inline-flex items-center gap-xs"><span class="w-[18px] border-t-[3px] border-dashed border-[#d9822b]" aria-hidden="true"></span>Median at baseline</span>
                </p>
                <table class="w-full text-[0.8125rem]">
                    <thead><tr class="text-left"><th class="{{ $th }}">Area</th><th class="{{ $th }} text-right">Baseline</th><th class="{{ $th }} text-right">Now</th></tr></thead>
                    <tbody>
                        @foreach ($areas as $k => $area)
                            <tr x-bind:class="axis === {{ $k }} ? 'bg-[#f7f5f1]' : ''">
                                <td class="{{ $td }} font-semibold" title="{{ $area['name'] }}">{{ $area['short'] }}</td>
                                <td class="{{ $td }} text-right">{{ $pct($shape['baseline'][$k]) }}</td>
                                <td class="{{ $td }} text-right font-semibold">{{ $pct($shape['now'][$k]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Zone by zone --}}
            @if ($byZone)
                <div x-show="mode === 'zones'" x-cloak class="overflow-x-auto">
                    <p class="flex flex-wrap gap-x-md gap-y-xs mb-sm text-[0.8125rem] text-on-surface-variant">
                        @foreach ($shape['zones'] as $i => $z)
                            <span class="inline-flex items-center gap-xs"><span class="w-[18px] border-t-[3px]" style="border-color: {{ $zoneColors[$i % 6] }}" aria-hidden="true"></span>{{ $z['name'] }}</span>
                        @endforeach
                    </p>
                    <table class="w-full text-[0.8125rem]">
                        <thead><tr class="text-left"><th class="{{ $th }}">Area</th>
                            @foreach ($shape['zones'] as $z)<th class="{{ $th }} text-right">{{ str_replace(' Africa', '', $z['name']) }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                            @foreach ($areas as $k => $area)
                                <tr x-bind:class="axis === {{ $k }} ? 'bg-[#f7f5f1]' : ''">
                                    <td class="{{ $td }} font-semibold" title="{{ $area['name'] }}">{{ $area['short'] }}</td>
                                    @foreach ($shape['zones'] as $z)<td class="{{ $td }} text-right">{{ $pct($z['values'][$k]) }}</td>@endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- The movements picked --}}
            <div x-show="mode === 'compare'" x-cloak class="overflow-x-auto">
                <p x-show="picked.length === 0" class="text-[0.875rem] text-on-surface-variant">Pick up to three movements above.</p>
                <table x-show="picked.length > 0" class="w-full text-[0.8125rem]">
                    <thead><tr class="text-left"><th class="{{ $th }}">Area</th>
                        <template x-for="(id, i) in picked" x-bind:key="id">
                            <th class="{{ $th }} text-right"><span class="inline-flex items-center gap-[6px]"><span class="size-2.5 rounded-full" x-bind:style="'background:' + colors[i]"></span><span x-text="names[id]"></span></span></th>
                        </template>
                    </tr></thead>
                    <tbody>
                        @foreach ($areas as $k => $area)
                            <tr x-bind:class="axis === {{ $k }} ? 'bg-[#f7f5f1]' : ''">
                                <td class="{{ $td }} font-semibold" title="{{ $area['name'] }}">{{ $area['short'] }}</td>
                                <template x-for="id in picked" x-bind:key="id"><td class="{{ $td }} text-right" x-text="cell(values[id][{{ $k }}])"></td></template>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-sm text-[0.8125rem] text-on-surface-variant">Hover a corner to pick out its row. Medians, so one movement does not pull the shape.</p>
        </div>
    </div>
</x-panel>
