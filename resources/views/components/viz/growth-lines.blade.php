{{--
    Growth over the cycle across the region (decided 15 Nov 2026): one thin ink line per
    movement followed up, from its baseline to its newest follow-up score, named at its end
    (names pushed apart where they would touch). The health bands' lower limits are hairlines
    named at the right; under each step, how many movements reached it.
      lines   list of ['movement' => Movement, 'steps' => list<float>] (from DashboardAnalytics)
      slots   how many steps a cycle has (the baseline and its follow-ups)
      height  the plot's height in px
--}}
@use('App\Support\Bands')
@use('App\Support\Viz')
@use('App\Models\Baseline')
@props(['lines', 'slots', 'height' => 260])
@php
    $lows = collect($lines)->flatMap(fn ($l) => $l['steps']);
    $from = max(0, (int) floor((($lows->min() ?? 50) - 10) / 10) * 10);
    $x = fn (int $i) => $slots > 1 ? $i * 100 / ($slots - 1) : 50;
    $y = fn (float $pct) => (100 - $pct) / (100 - $from) * 100;
    // The names at each line's end, pushed apart by at least 18px where they share a step.
    $ends = collect($lines)->map(fn ($l) => [
        'name' => str_replace(' YMCA', '', $l['movement']->name),
        'step' => count($l['steps']) - 1,
        'px' => $y((float) end($l['steps'])) / 100 * $height,
        'pct' => (float) end($l['steps']),
    ])->sortBy(['step', 'px'])->values()->all();
    for ($i = 1; $i < count($ends); $i++) {
        if ($ends[$i]['step'] === $ends[$i - 1]['step'] && $ends[$i]['px'] - $ends[$i - 1]['px'] < 18) {
            $ends[$i]['px'] = $ends[$i - 1]['px'] + 18;
        }
    }
    $bands = collect(Bands::scale())->filter(fn ($b) => $b['from'] > $from);
@endphp
<figure {{ $attributes->class('flex flex-col gap-sm') }}>
    <div class="grid grid-cols-[1fr_7rem] gap-x-sm">
        <div class="relative" style="height: {{ $height }}px">
            <span class="absolute inset-x-0 bottom-0 border-t border-[#d9d7d1]" aria-hidden="true"></span>
            @foreach ($bands as $band)
                <span class="absolute inset-x-0 border-t border-dashed border-[#e2e0da]" style="top: {{ $y($band['from']) }}%" aria-hidden="true"></span>
                <span class="absolute left-1 -translate-y-full pb-[2px] text-[0.6875rem] text-on-surface-variant whitespace-nowrap" style="top: {{ $y($band['from']) }}%" aria-hidden="true">{{ $band['label'] }} {{ Viz::figure($band['from']) }}%</span>
            @endforeach
            <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" role="img"
                 aria-label="{{ collect($lines)->map(fn ($l) => $l['movement']->name.': '.collect($l['steps'])->map(fn ($s) => Viz::pct($s))->join(' to '))->join('; ') }}">
                @foreach ($lines as $line)
                    <polyline points="{{ collect($line['steps'])->map(fn ($s, $i) => round($x($i), 2).','.round($y((float) $s), 2))->join(' ') }}"
                              fill="none" stroke="var(--color-on-surface)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                @endforeach
            </svg>
            @foreach ($lines as $line)
                @php($last = count($line['steps']) - 1)
                <span class="absolute size-[8px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-on-surface shadow-[0_0_0_2px_#ffffff]"
                      style="left: {{ $x($last) }}%; top: {{ $y((float) $line['steps'][$last]) }}%" aria-hidden="true"></span>
            @endforeach
            @foreach ($ends as $end)
                <span class="absolute -translate-y-1/2 text-[0.75rem] font-semibold whitespace-nowrap bg-surface-container-lowest/80 px-1 rounded"
                      style="top: {{ $end['px'] }}px; {{ $end['step'] === $slots - 1 ? 'right: 0; transform: translate(calc(100% + 6px), -50%)' : 'left: calc('.$x($end['step']).'% + 8px)' }}"
                      aria-hidden="true">{{ $end['name'] }} {{ Viz::figure(round($end['pct'])) }}</span>
            @endforeach
        </div>
        {{-- The names of the lines that reach the last step sit in this column. --}}
        <div aria-hidden="true"></div>
        <div class="relative h-[2.5rem] text-[0.78125rem]" aria-hidden="true">
            @for ($i = 0; $i < $slots; $i++)
                @php($n = collect($lines)->filter(fn ($l) => count($l['steps']) > $i)->count())
                <span @class(['absolute top-xs flex flex-col whitespace-nowrap', '-translate-x-1/2 items-center' => $i > 0 && $i < $slots - 1, 'items-start' => $i === 0, '-translate-x-full items-end' => $i === $slots - 1])
                      style="left: {{ $x($i) }}%">
                    <span class="font-semibold">{{ $i === 0 ? Baseline::name(1) : 'Follow-up '.$i }}</span>
                    <span class="text-on-surface-variant">{{ $n }} {{ \Illuminate\Support\Str::plural('movement', $n) }}</span>
                </span>
            @endfor
        </div>
    </div>
</figure>
