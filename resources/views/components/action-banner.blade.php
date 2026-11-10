{{--
    A slim banner for the one thing waiting on this person (design standard of 10 Nov 2026):
    the red "next" marker, one line, one button. Shown only when there is something to do;
    otherwise the page opens straight onto what it shows.
      tone  action (default: the red marker) · info (a notice, blue)
--}}
@props(['title', 'href' => null, 'button' => null, 'tone' => 'action'])
<div {{ $attributes->class([
    'flex flex-wrap items-center gap-x-md gap-y-sm rounded-2xl border px-[18px] py-[12px] shadow-[0_1px_2px_rgba(20,20,20,0.04)]',
    'bg-surface-container-lowest border-[#ecd3d3]' => $tone === 'action',
    'bg-info-wash border-[#cfdcf0]' => $tone === 'info',
]) }} role="status">
    @if ($tone === 'action')
        <span class="flex-none size-[18px] rounded-full box-border border-[4px] border-brand-red-ink bg-surface-container-lowest shadow-[0_0_0_4px_rgba(200,16,46,0.14)]" aria-hidden="true"></span>
    @else
        <span class="flex-none size-2.5 rounded-full bg-info-ink" aria-hidden="true"></span>
    @endif
    <p class="flex-1 min-w-[min(14rem,100%)] text-[0.9375rem] font-semibold">{{ $title }}</p>
    @if ($href && $button)
        <a href="{{ $href }}" class="inline-flex items-center gap-sm min-h-10 px-[14px] rounded-[10px] bg-on-surface text-white text-[0.875rem] font-semibold hover:bg-black focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
            {{ $button }}
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    @endif
</div>
