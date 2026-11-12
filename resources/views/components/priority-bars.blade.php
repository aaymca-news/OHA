{{--
    An ODP's strategic priorities, each as one bar split into done (green), blocked (red)
    and still open (grey), as set at the last follow-up, with done of total beside it. Expects $priorities from
    App\Queries\OdpProgress::byPriority().
--}}
@props(['priorities'])
<div {{ $attributes->class('flex flex-col gap-sm') }}>
    <div class="flex flex-wrap items-center justify-between gap-sm">
        <p class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">By priority</p>
        <span class="flex gap-[12px] text-[0.75rem] text-on-surface-variant" aria-hidden="true">
            <span class="inline-flex items-center gap-[5px]"><span class="size-2 rounded-[2px] bg-band-strong"></span>Done</span>
            <span class="inline-flex items-center gap-[5px]"><span class="size-2 rounded-[2px] bg-band-critical"></span>Blocked</span>
            <span class="inline-flex items-center gap-[5px]"><span class="size-2 rounded-[2px] bg-[#dddad3]"></span>Open</span>
        </span>
    </div>
    <ul class="flex flex-col gap-[11px]">
        @foreach ($priorities as $p)
            @php($name = \Illuminate\Support\Str::title(mb_strtolower($p['priority'])))
            <li class="grid grid-cols-[minmax(0,1fr)_minmax(6rem,9.5rem)_2.75rem] items-center gap-[12px] text-[0.84375rem]">
                <span class="truncate" title="{{ $name }}">{{ $name }}</span>
                <span class="flex h-2 rounded-full overflow-hidden bg-[#dddad3]" role="img"
                      aria-label="{{ $name }}: {{ $p['done'] }} of {{ $p['total'] }} done{{ $p['blocked'] > 0 ? ', '.$p['blocked'].' blocked' : '' }}">
                    @if ($p['done'] > 0)<span class="bg-band-strong" style="width: {{ $p['done'] / max(1, $p['total']) * 100 }}%"></span>@endif
                    @if ($p['blocked'] > 0)<span class="bg-band-critical {{ $p['done'] > 0 ? 'ml-0.5' : '' }}" style="width: {{ $p['blocked'] / max(1, $p['total']) * 100 }}%"></span>@endif
                </span>
                <span class="text-right tabular-nums text-[0.8125rem] text-on-surface-variant">{{ $p['done'] }}/{{ $p['total'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
