{{-- The four gates in order: done (green tick), current (navy), locked or waiting (grey). --}}
@props(['milestones', 'current' => null])
<ol class="grid grid-cols-2 sm:grid-cols-4 gap-sm" aria-label="Approval gates">
    @foreach ($milestones as $i => $m)
        @php
            $done = $m->done_at !== null;
            $isCurrent = ! $done && $m->gate_code === $current;
        @endphp
        <li @class([
            'p-sm rounded-lg border-[1.5px] flex flex-col gap-xs',
            'border-band-strong bg-good-wash' => $done,
            'border-primary bg-surface-container-lowest' => $isCurrent,
            'border-outline-variant bg-surface-container-low' => ! $done && ! $isCurrent,
        ])>
            <span class="flex items-center gap-xs text-[0.8125rem] font-semibold {{ $done ? 'text-good-ink' : 'text-primary' }}">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">{{ $done ? 'check_circle' : ($isCurrent ? 'radio_button_checked' : 'radio_button_unchecked') }}</span>
                {{ $i + 1 }}. {{ $m->milestone_label }}
            </span>
            <span class="text-[0.8125rem] text-on-surface-variant">
                @if ($done)
                    Done {{ $m->done_at->format('j M Y') }}{{ $m->completed_late ? ' (late)' : '' }}
                @else
                    Due {{ $m->due_on->format('j M Y') }}{{ $m->set_by_hand ? ' · set by hand' : '' }}{{ $m->overdue ? ' · overdue' : '' }}
                @endif
            </span>
        </li>
    @endforeach
</ol>
