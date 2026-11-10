{{--
    Where all the movements are in Stage 1, as one bar of ordered steps: grey for not yet
    assessed, then one hue darkening from the OHA form to complete. Counts are printed in
    the legend and on hover.
    Expects $pipeline from AllianceInsights::pipeline().
--}}
@props(['pipeline'])
@php
    // Each stage's short label and fill come from App\Enums\Stage.
    $steps = collect(\App\Enums\Stage::cases())->mapWithKeys(fn ($s) => [$s->value => [$s->short(), $s->fill()]])->all();
    $total = max(1, $pipeline['total']);
@endphp
<div class="flex flex-col gap-sm">
    <div class="flex gap-[3px] h-3.5 rounded-full overflow-hidden" role="img"
         aria-label="{{ collect($steps)->map(fn ($s, $k) => $s[0].': '.$pipeline[$k])->join(', ') }}">
        @foreach ($steps as $key => [$label, $fill])
            @continue($pipeline[$key] === 0)
            <span class="{{ $fill }} h-full" style="width: {{ $pipeline[$key] * 100 / $total }}%" title="{{ $label }}: {{ $pipeline[$key] }}"></span>
        @endforeach
    </div>
    <ul class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-sm">
        @foreach ($steps as $key => [$label, $fill])
            <li class="flex items-start gap-xs">
                <span class="mt-1.5 size-2.5 rounded-full shrink-0 {{ $fill }}" aria-hidden="true"></span>
                <span>
                    <span class="block text-[1.25rem] font-bold text-on-surface leading-tight tabular-nums">{{ $pipeline[$key] }}</span>
                    <span class="block text-[0.8125rem] text-on-surface-variant">{{ $label }}</span>
                </span>
            </li>
        @endforeach
    </ul>
</div>
