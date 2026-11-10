{{--
    A status as a soft pill with a coloured dot: the site's one status language (design
    standard of 10 Nov 2026).
      tone  good (Done) · info (Waiting to confirm) · warning (In progress)
            · critical (Overdue, blocked) · neutral (Not started, dropped)
            · atrisk (the At Risk health band only)
--}}
@props(['tone' => 'neutral'])
@php
    [$wash, $ink, $dot] = match ($tone) {
        'good' => ['bg-good-wash', 'text-good-ink', 'bg-band-strong'],
        'atrisk' => ['bg-serious-wash', 'text-serious-ink', 'bg-band-atrisk'],
        'info' => ['bg-info-wash', 'text-info-ink', 'bg-info-ink'],
        'warning' => ['bg-warning-wash', 'text-warning-ink', 'bg-band-developing'],
        'critical', 'serious' => ['bg-critical-wash', 'text-critical-ink', 'bg-band-critical'],
        default => ['bg-neutral-wash', 'text-neutral-ink', 'bg-band-notstarted'],
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-[6px] rounded-full px-[10px] py-[4px] text-[0.78125rem] font-semibold whitespace-nowrap', $wash, $ink]) }}>
    <span class="size-[7px] rounded-full {{ $dot }}" aria-hidden="true"></span>{{ $slot }}
</span>
