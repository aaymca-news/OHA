{{--
    Steps one under another (design standard of 10 Nov 2026): done steps a green tick, the
    next a red ring with a soft halo (never a badge), the rest grey. Expects $steps as
    list of ['title', 'meta', 'state' => done|next|todo, 'late' => bool], e.g. from
    App\Support\MovementCycle::steps().
--}}
@props(['steps'])
<ol {{ $attributes->class('flex flex-col') }}>
    @foreach ($steps as $s)
        <li class="grid grid-cols-[24px_1fr] gap-[12px]" @if ($s['state'] === 'next') aria-current="step" @endif>
            <span class="flex flex-col items-center">
                @if ($s['state'] === 'done')
                    <span class="size-5 rounded-full bg-band-strong grid place-items-center flex-none">
                        <svg class="size-[11px]" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>
                    </span>
                @elseif ($s['state'] === 'next')
                    <span class="size-5 rounded-full box-border border-[5px] border-brand-red-ink bg-surface-container-lowest shadow-[0_0_0_4px_rgba(200,16,46,0.14)] flex-none"></span>
                @else
                    <span class="size-5 rounded-full box-border border-2 border-[#d3d0c9] bg-surface-container-lowest flex-none"></span>
                @endif
                @unless ($loop->last)
                    <span @class(['flex-1 w-0.5 min-h-[18px]', 'bg-band-strong' => $s['state'] === 'done', 'bg-[#e4e1db]' => $s['state'] !== 'done'])></span>
                @endunless
            </span>
            <span @class(['flex flex-col', 'pb-[14px]' => ! $loop->last])>
                <span @class(['text-[0.875rem]', 'font-bold' => $s['state'] === 'next', 'font-medium' => $s['state'] !== 'next'])>
                    {{ $s['title'] }}<span class="sr-only">{{ match ($s['state']) { 'done' => ' (done)', 'next' => ' (next)', default => '' } }}</span>
                </span>
                <span @class(['text-[0.78125rem]', 'text-critical-ink font-semibold' => $s['late'], 'text-on-surface-variant' => ! $s['late']])>{{ $s['meta'] }}</span>
            </span>
        </li>
    @endforeach
</ol>
