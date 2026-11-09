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
    'bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg' => ! $bare,
])>
    <table {{ $attributes->class('oha-table w-full text-[0.875rem]') }}>
        @if ($caption)
            <caption class="text-left px-md pt-md text-[1rem] font-bold text-primary">{{ $caption }}</caption>
        @endif
        @isset($head)
            <thead class="text-left text-[0.8125rem] uppercase tracking-wider text-on-surface-variant">
                {{ $head }}
            </thead>
        @endisset
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
