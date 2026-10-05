@props(['title', 'subtitle' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/aaymca-icon.png') }}">
    @include('partials.display-preferences')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen" x-data="layout">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:m-sm focus:p-sm focus:bg-surface-container-lowest focus:z-50">Skip to content</a>

    <div class="flex min-h-screen">
        {{--
            Sidebar. On wide screens it can be dragged wider (up to a quarter of the window)
            and hidden altogether; on small screens it opens over the page from the menu button.
        --}}
        {{-- On small screens the open menu floats over the page; tapping outside or Escape closes it. --}}
        <div x-show="menu" x-cloak x-on:click="menu = false" class="lg:hidden fixed inset-0 z-30 bg-primary/40" aria-hidden="true"></div>
        <div id="sidebar" class="sidebar-shell w-60 max-w-[85vw] shrink-0 lg:relative bg-surface-container-lowest border-r-[1.5px] border-outline-variant hidden lg:block"
             :class="menu ? '!block fixed inset-y-0 left-0 z-40 shadow-lg' : ''" x-on:keydown.escape.window="menu = false">
            <aside class="bg-surface-container-lowest text-on-surface flex flex-col sticky top-0 h-screen" aria-label="Main menu">
                <a href="{{ route('dashboard') }}" class="block px-md pt-md pb-sm border-b border-surface-container">
                    <img src="{{ asset('images/aaymca-logo.png') }}" alt="{{ __('oha.app.org') }}" width="1200" height="452" class="w-full max-w-[14rem] h-auto">
                    <span class="block mt-sm text-[0.875rem] font-semibold text-on-surface-variant leading-tight">{{ __('oha.app.title') }}</span>
                </a>
                <nav class="p-sm flex flex-col gap-0.5 flex-1 overflow-y-auto" aria-label="Main">
                    @foreach ($navigation as $item)
                        @php($isActive = request()->routeIs($item['active']))
                        <a href="{{ route($item['route'], $item['params']) }}" @if ($isActive) aria-current="page" @endif
                           @class([
                               'flex items-center gap-sm px-md py-2.5 rounded text-[0.9375rem] border-l-[3px]',
                               'border-brand-red bg-brand-red-wash text-primary font-semibold' => $isActive,
                               'border-transparent text-on-surface hover:bg-surface-container-low' => ! $isActive,
                           ])>
                            <span @class(['material-symbols-outlined text-[1.25rem]', 'text-brand-red-ink' => $isActive]) aria-hidden="true">{{ $item['icon'] }}</span>
                            <span class="flex-1 min-w-0 truncate">{{ $item['label'] }}</span>
                            @if ($item['badge'] > 0)
                                <span @class([
                                    'min-w-6 px-1.5 rounded-full text-[0.8125rem] font-bold text-center',
                                    'bg-brand-red-ink text-white' => $item['tone'] === 'critical',
                                    'bg-secondary-container text-primary' => $item['tone'] !== 'critical',
                                ])>{{ $item['badge'] }}<span class="sr-only"> waiting</span></span>
                            @endif
                        </a>
                    @endforeach
                </nav>
            </aside>

            {{-- Drag (or use the arrow keys on) the edge to resize. Double-click to reset. --}}
            <div role="separator" tabindex="0" aria-orientation="vertical" aria-controls="sidebar" aria-label="Resize the menu"
                 :aria-valuenow="width" aria-valuemin="208" :aria-valuemax="maxWidth()"
                 class="hidden lg:block absolute inset-y-0 -right-1.5 w-3 cursor-col-resize group z-10 touch-none"
                 x-on:pointerdown="startResize($event)" x-on:dblclick="resetWidth()" x-on:keydown="keyResize($event)">
                <span class="absolute inset-y-0 left-1/2 -translate-x-1/2 w-1 rounded bg-transparent group-hover:bg-brand-red group-focus-visible:bg-brand-red" aria-hidden="true"></span>
            </div>
        </div>

        <div class="flex-1 min-w-0 flex flex-col">
            {{-- Top bar --}}
            <header class="sticky top-0 z-30 bg-surface-container-lowest/95 backdrop-blur border-b-[1.5px] border-outline-variant">
                <div class="flex items-center gap-sm sm:gap-md px-md sm:px-lg py-2">
                    <button type="button" x-on:click="toggleSidebar()" aria-controls="sidebar" :aria-expanded="sidebarOpen()"
                            :aria-label="sidebarOpen() ? 'Hide the menu' : 'Show the menu'" :title="sidebarOpen() ? 'Hide the menu' : 'Show the menu'"
                            class="p-1.5 rounded border-[1.5px] border-outline-variant hover:border-primary flex items-center">
                        <span class="material-symbols-outlined" aria-hidden="true" x-text="sidebarOpen() ? 'left_panel_close' : 'left_panel_open'">menu</span>
                    </button>
                    <a href="{{ route('dashboard') }}" class="topbar-logo shrink-0">
                        <img src="{{ asset('images/aaymca-logo.png') }}" alt="{{ __('oha.app.org') }}" width="1200" height="452" class="h-8 w-auto">
                    </a>

                    <form method="GET" action="{{ route('search') }}" role="search" class="relative flex-1 min-w-0 max-w-[28rem]">
                        <label for="q" class="sr-only">Search movements and assessments</label>
                        <span class="material-symbols-outlined absolute left-2 top-1/2 -translate-y-1/2 text-[1.125rem] text-on-surface-variant" aria-hidden="true">search</span>
                        <input id="q" name="q" type="search" value="{{ request('q') }}" placeholder="Search movements, assessments…"
                               class="w-full pl-8 pr-3 py-1.5 text-[0.9375rem] rounded border-[1.5px] border-outline bg-surface-container-lowest focus:border-primary">
                    </form>

                    <div class="ml-auto flex items-center gap-sm sm:gap-md shrink-0">
                    <a href="{{ route('notifications.index') }}" class="relative p-1.5 rounded-full hover:bg-surface-container" aria-label="Notifications{{ $unread ? ', '.$unread.' unread' : '' }}">
                        <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
                        @if ($unread)
                            <span class="absolute -top-1.5 -right-1.5 min-w-[1.375rem] h-[1.375rem] px-1 rounded-full bg-brand-red-ink text-white text-[0.8125rem] font-bold flex items-center justify-center" aria-hidden="true">{{ $unread > 9 ? '9+' : $unread }}</span>
                        @endif
                    </a>

                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                        <button type="button" x-on:click="open = ! open" :aria-expanded="open" aria-haspopup="true"
                                class="flex items-center gap-sm pl-1 pr-2 py-1 rounded-full border-[1.5px] border-outline-variant hover:border-primary">
                            <span class="w-8 h-8 rounded-full bg-primary-container text-on-primary text-[0.8125rem] font-bold flex items-center justify-center" aria-hidden="true">
                                {{ collect(explode(' ', auth()->user()->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
                            </span>
                            <span class="text-left leading-tight hidden md:block">
                                <span class="block text-[0.875rem] font-semibold text-primary">{{ auth()->user()->name }}</span>
                                <span class="block text-[0.8125rem] text-on-surface-variant">{{ auth()->user()->role->label() }}{{ auth()->user()->movement ? ' · '.auth()->user()->movement->name : '' }}</span>
                            </span>
                            <span class="material-symbols-outlined text-[1.125rem] text-on-surface-variant" aria-hidden="true">expand_more</span>
                        </button>
                        <div x-show="open" x-cloak class="absolute right-0 mt-1 w-64 bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg py-xs text-[0.9375rem] shadow-md">
                            {{-- Text size: remembered on this device. Everything is sized in rem, so it all scales. --}}
                            <div class="px-md py-2 border-b border-surface-container" role="group" aria-labelledby="text-size-label">
                                <p id="text-size-label" class="text-[0.8125rem] font-semibold text-on-surface-variant mb-xs">Text size</p>
                                <div class="flex gap-xs">
                                    @foreach (['normal' => ['A', 'Normal', 'text-[0.875rem]'], 'large' => ['A', 'Large', 'text-[1.0625rem]'], 'larger' => ['A', 'Larger', 'text-[1.25rem]']] as $key => [$glyph, $label, $size])
                                        <button type="button" x-on:click="setTextSize('{{ $key }}')" :aria-pressed="textSize === '{{ $key }}'" aria-label="{{ $label }} text"
                                                :class="textSize === '{{ $key }}' ? 'bg-primary text-on-primary border-primary' : 'border-outline-variant hover:border-primary'"
                                                class="flex-1 h-9 rounded border-[1.5px] font-bold {{ $size }}">{{ $glyph }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <a href="{{ route('security.show') }}" class="block px-md py-2 hover:bg-surface-container">{{ __('oha.nav.security') }}</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="w-full text-left px-md py-2 hover:bg-surface-container">Sign out</button>
                            </form>
                        </div>
                    </div>
                    </div>
                </div>
            </header>

            <main id="main" class="flex-1 px-lg py-lg w-full max-w-[1280px] flex flex-col gap-md">
                @if (! $health['ok'] && \App\Support\SystemHealth::shouldWarn(auth()->user()))
                    <div role="alert" @class([
                        'flex flex-wrap items-center gap-sm p-md rounded-lg border-[1.5px]',
                        'border-band-critical bg-critical-wash text-critical-ink' => $health['severity'] === 'critical',
                        'border-band-atrisk bg-serious-wash text-serious-ink' => $health['severity'] !== 'critical',
                    ])>
                        <span class="material-symbols-outlined" aria-hidden="true">warning</span>
                        <div class="flex-1 min-w-60">
                            <p class="text-[0.9375rem] font-semibold">Roles still to be appointed</p>
                            <p class="text-[0.875rem]">{{ implode(' ', $health['problems']) }}</p>
                            @if ($health['movements_without_chair'] !== [])
                                <details class="text-[0.875rem] mt-xs">
                                    <summary class="cursor-pointer underline">Movements without a Board Chairperson ({{ count($health['movements_without_chair']) }})</summary>
                                    <p class="mt-xs">{{ implode(', ', $health['movements_without_chair']) }}</p>
                                </details>
                            @endif
                            <p class="text-[0.8125rem] mt-xs">You will be reminded daily until these are appointed.</p>
                        </div>
                        @can('manage', \App\Models\User::class)
                            <a href="{{ route('admin.users.index') }}" class="px-md py-1.5 rounded bg-primary text-on-primary text-[0.875rem] font-semibold">Appoint in Users &amp; Roles</a>
                        @endcan
                    </div>
                @endif

                <div class="flex flex-wrap items-start gap-md">
                    <div class="flex-1 min-w-60">
                        <h1 class="text-[1.5rem] font-bold text-primary leading-tight">{{ $title }}</h1>
                        @if ($subtitle)
                            <p class="text-[0.9375rem] text-on-surface-variant mt-1">{{ $subtitle }}</p>
                        @endif
                    </div>
                    {{ $actions ?? '' }}
                </div>

                <x-flash />
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
