@props(['name'])
{{--
    Line icons for the public front page, drawn on a 24px grid. The app's icon font is
    4 MB, too heavy for a first visit on a phone, so this page draws its own.
--}}
<svg {{ $attributes->merge(['class' => 'size-6']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('financial')
            <ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>
            @break
        @case('governance')
            <path d="M3 9l9-5 9 5"/><path d="M5 10v8M9.7 10v8M14.3 10v8M19 10v8"/><path d="M3 20.5h18"/>
            @break
        @case('constitution')
            <path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5"/><path d="M9 13h7M9 17h7"/>
            @break
        @case('me')
            <path d="M4 20h16"/><path d="M4 16l5-5 4 3 7-7"/><path d="M15 7h5v5"/>
            @break
        @case('strategy')
            <path d="M5 21V4"/><path d="M5 4h12l-2.5 4L17 12H5"/>
            @break
        @case('diversity')
        @case('team')
            <circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.5"/><path d="M16.5 14.1c2.6.5 4.5 2.9 4.5 5.9"/>
            @break
        @case('comms')
            <path d="M4 10v4h3l8 4V6l-8 4z"/><path d="M18.5 9c1 .8 1.5 1.8 1.5 3s-.5 2.2-1.5 3"/><path d="M7 14l1 5h3l-1-4"/>
            @break
        @case('property')
            <path d="M3 11l9-7 9 7"/><path d="M5 9.5V20h14V9.5"/><path d="M10 20v-6h4v6"/>
            @break
        @case('staff')
            <circle cx="10" cy="7" r="3"/><path d="M4 20c0-3.3 2.7-6 6-6 1.4 0 2.6.4 3.6 1.2"/><path d="M18 21v-7M15 17l3-3 3 3"/>
            @break
        @case('know')
            <circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.4-4.4"/><path d="M8.5 11h5M11 8.5v5"/>
            @break
        @case('grow')
            <path d="M12 21v-9"/><path d="M12 12c0-4.2 3-7 8-7 0 5-3 7-8 7z"/><path d="M12 14.5C12 11.4 9.8 9 6 9c0 3.8 2.2 5.5 6 5.5z"/>
            @break
        @case('shield')
            <path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>
            @break
        @case('clipboard')
            <rect x="5.5" y="4.5" width="13" height="16.5" rx="2"/><path d="M9 3h6v3.5H9z"/><path d="M9 13.5l2 2 4-4.5"/>
            @break
        @case('pen')
            <path d="M3.5 20.5h7"/><path d="M15 4l5 5L9.5 19.5h-5v-5z"/><path d="M12.5 6.5l5 5"/>
            @break
        @case('lock')
            <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><path d="M12 15v2"/>
            @break
        @case('share')
            <circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="M8.2 10.8l7.6-4.1M8.2 13.2l7.6 4.1"/>
            @break
        @case('repeat')
            <path d="M4 12a8 8 0 0 1 13.7-5.7L20 8.5"/><path d="M20 4v4.5h-4.5"/><path d="M20 12a8 8 0 0 1-13.7 5.7L4 15.5"/><path d="M4 20v-4.5h4.5"/>
            @break
        @case('arrow-right')
            <path d="M5 12h14M13 6l6 6-6 6"/>
            @break
        @case('arrow-down')
            <path d="M12 5v14M6 13l6 6 6-6"/>
            @break
        @case('excellent')
            <path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>
            @break
        @case('strong')
            <circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.2l2.4 2.4 4.6-5"/>
            @break
        @case('developing')
            <path d="M3.5 17l6-6 4 4 7-7"/><path d="M15 8h5.5v5.5"/>
            @break
        @case('atrisk')
            <path d="M12 4L2.8 19.5h18.4z"/><path d="M12 10v4.2M12 17h.01"/>
            @break
        @case('critical')
            <circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5.5M12 16.3h.01"/>
            @break
    @endswitch
</svg>
