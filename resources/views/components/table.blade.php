{{--
    A data table in its card, scrolling sideways on a phone. Put the header row in the
    `head` slot (<tr><th>…</th></tr>) and the body rows in the slot. Cells get the standard
    spacing (see .oha-table in app.css); a cell's own classes still win.
      caption  the table's title, shown above it (also read by screen readers)
      bare     true for a table inside a card that already has a border
--}}
@props(['caption' => null, 'bare' => false])
<div @class([
    'relative overflow-x-auto',
    'bg-surface-container-lowest border border-[#e7e5e0] rounded-2xl shadow-[0_1px_2px_rgba(20,20,20,0.04)]' => ! $bare,
])>
    <table {{ $attributes->class('oha-table w-full text-[0.875rem]') }}>
        @if ($caption)
            <caption class="text-left px-md pt-md text-[1.125rem] font-bold text-on-surface">{{ $caption }}</caption>
        @endif
        @isset($head)
            <thead class="text-left text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">
                {{ $head }}
            </thead>
        @endisset
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
