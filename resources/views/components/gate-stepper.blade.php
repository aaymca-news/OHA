{{--
    The four approval gates in order, as one line of steps (design standard of 10 Nov 2026):
    done a green tick, the current one a red ring with a soft halo, the rest grey; each with
    its date beneath (red once overdue).
--}}
@props(['milestones', 'current' => null])
<ol {{ $attributes->class('grid grid-cols-2 sm:grid-cols-4 gap-y-md') }} aria-label="Approval gates">
    @foreach ($milestones as $i => $m)
        @php
            $done = $m->done_at !== null;
            $isCurrent = ! $done && $m->gate_code === $current;
            $late = ! $done && $m->overdue;
        @endphp
        <li class="relative flex flex-col gap-[6px] pr-sm" @if ($isCurrent) aria-current="step" @endif>
            <span class="flex items-center">
                @if ($done)
                    <span class="size-5 rounded-full bg-band-strong grid place-items-center flex-none">
                        <svg class="size-[11px]" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>
                    </span>
                @elseif ($isCurrent)
                    <span class="size-5 rounded-full box-border border-[5px] border-brand-red-ink bg-surface-container-lowest shadow-[0_0_0_4px_rgba(200,16,46,0.14)] flex-none"></span>
                @else
                    <span class="size-5 rounded-full box-border border-2 border-[#d3d0c9] bg-surface-container-lowest flex-none"></span>
                @endif
                @unless ($loop->last)
                    <span @class(['hidden sm:block h-0.5 flex-1 ml-sm', 'bg-band-strong' => $done, 'bg-[#e4e1db]' => ! $done]) aria-hidden="true"></span>
                @endunless
            </span>
            <span @class(['text-[0.875rem]', 'font-bold' => $isCurrent, 'font-medium' => ! $isCurrent])>
                {{ $i + 1 }}. {{ $m->milestone_label }}<span class="sr-only">{{ $done ? ' (done)' : ($isCurrent ? ' (now)' : '') }}</span>
            </span>
            <span @class(['text-[0.78125rem]', 'font-semibold text-critical-ink' => $late, 'text-on-surface-variant' => ! $late])>
                @if ($done)
                    Done {{ $m->done_at->asDay() }}{{ $m->completed_late ? ' (late)' : '' }}
                @else
                    Due {{ $m->due_on->asDay() }}{{ $m->set_by_hand ? ' · set by hand' : '' }}{{ $m->overdue ? ' · overdue' : '' }}
                @endif
            </span>
        </li>
    @endforeach
</ol>
