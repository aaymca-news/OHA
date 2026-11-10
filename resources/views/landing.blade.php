{{--
    The public front page. It explains the Organisational Health Assessment, its purpose
    and its approach, and shows no data: no movement, score or count. It also stays at
    the level of purpose: it names no roles, steps, approvals or timetables, so it tells
    an outsider nothing about who does what inside the platform. Motion is gentle, plays
    once, and is switched off for anyone who has asked their device to reduce it.
--}}
@php
    $title = 'Organisational Health Assessment · '.__('oha.app.org');
    $description = 'The Organisational Health Assessment helps every YMCA National Movement in Africa see where it is strong, where it is at risk, and what to do next.';

    // One plain sentence per area: why it matters to a movement's health.
    $why = [
        'financial' => 'Income that is diverse, reliable and well managed, so the work can last.',
        'governance' => 'Leadership that sets direction and holds the movement to account.',
        'constitution' => 'Clear rules and policies, kept up to date and followed.',
        'me' => 'Knowing what works, measuring it, and learning from what does not.',
        'strategy' => 'A shared plan that turns the vision into clear priorities.',
        'diversity' => 'Young people with a real voice and place in leadership.',
        'comms' => 'Telling the YMCA story clearly, consistently and well.',
        'property' => 'Buildings and assets that are cared for and used well.',
        'staff' => 'Staff and volunteers who are trained, supported and growing.',
    ];

    // The approach as a cycle, in broad strokes only.
    $cycle = [
        ['know', 'Reflect', 'Take an honest look at the whole organisation, area by area.'],
        ['strategy', 'Prioritise', 'Agree on what matters most for the movement’s development.'],
        ['grow', 'Act', 'Turn those priorities into action, with support along the way.'],
        ['repeat', 'Grow', 'See progress over time, celebrate it, and keep building.'],
    ];

    // What each band means for a movement, weakest first.
    $meaning = [
        'critical' => 'The foundations need urgent attention and close support.',
        'atrisk' => 'Important gaps that call for focused support.',
        'developing' => 'Good foundations, with clear room to grow.',
        'strong' => 'A healthy organisation building on its strengths.',
        'excellent' => 'A model of good practice for others to learn from.',
    ];
    $scale = $bands->sortByDesc('sort_order')->values();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="landing">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if (config('oha.demo'))
        {{-- The demo site is never indexed by search engines. --}}
        <meta name="robots" content="noindex, nofollow">
    @endif
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ __('oha.app.org') }}">
    <meta property="og:title" content="Strong, healthy YMCAs across Africa">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url('/') }}">
    {{-- The link preview (WhatsApp, LinkedIn, email). A new file name makes the apps fetch it afresh. --}}
    <meta property="og:image" content="{{ asset('images/oha-preview.png') }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="The Africa Alliance of YMCAs mark inside a red ring of nine segments, one for each area of the Organisational Health Assessment, beside the words Strong, healthy YMCAs across Africa and the nine areas listed.">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('images/oha-preview.png') }}">
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" type="image/png" href="{{ asset('images/aaymca-icon.png') }}">
    <script>document.documentElement.classList.add('js');</script>
    @include('partials.display-preferences')
    @vite(['resources/css/app.css'])
</head>
<body class="bg-surface-container-lowest">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:m-sm focus:p-sm focus:bg-surface-container-lowest focus:z-50">Skip to content</a>
    @include('partials.demo-banner')

    <header class="sticky top-0 z-30 bg-surface-container-lowest border-b border-surface-container">
        <div class="mx-auto max-w-[72rem] px-md h-16 flex items-center gap-md">
            <a href="{{ route('home') }}" class="shrink-0">
                <img src="{{ asset('images/aaymca-logo.png') }}" alt="{{ __('oha.app.org') }}" width="1200" height="452" class="w-[7.5rem] sm:w-[8.5rem] h-auto">
            </a>
            <nav class="hidden md:flex items-center gap-lg ml-auto text-[0.9375rem] font-medium text-on-surface-variant" aria-label="On this page">
                <a href="#purpose" class="hover:text-primary">Purpose</a>
                <a href="#areas" class="hover:text-primary">The nine areas</a>
                <a href="#how" class="hover:text-primary">How it works</a>
            </nav>
            <x-landing.sign-in class="ml-auto md:ml-0 inline-flex items-center gap-xs rounded-lg bg-primary text-on-primary px-md py-[0.55rem] text-[0.9375rem] font-semibold hover:bg-primary-container" />
        </div>
    </header>

    <main id="main">
        {{-- Opening --}}
        <section class="landing-hero overflow-hidden">
            <div class="mx-auto max-w-[72rem] px-md pt-xl pb-[4.5rem] lg:pt-[5rem] lg:pb-[6.5rem] grid lg:grid-cols-[1.1fr_0.9fr] gap-xl items-center">
                <div>
                    <p class="text-[0.8125rem] font-bold uppercase tracking-[0.14em] text-brand-red-ink" data-reveal>Organisational Health Assessment</p>
                    <h1 class="mt-md text-[2.5rem] sm:text-[3.25rem] lg:text-[4rem] font-bold leading-[1.04] tracking-tight text-primary" data-reveal style="--d: 1">
                        Strong, <span class="text-brand-red">healthy</span> YMCAs across Africa.
                    </h1>
                    <p class="mt-lg max-w-[34rem] text-[1.125rem] sm:text-[1.25rem] leading-relaxed text-on-surface-variant" data-reveal style="--d: 2">
                        The Organisational Health Assessment helps every National Movement see where it is strong, where it is at risk, and what to do next.
                    </p>
                    <div class="mt-xl flex flex-wrap gap-sm" data-reveal style="--d: 3">
                        <x-landing.sign-in class="inline-flex items-center gap-sm rounded-lg bg-primary text-on-primary px-lg py-[0.8rem] text-[1rem] font-semibold hover:bg-primary-container">
                            <x-landing.icon name="arrow-right" class="size-5" />
                        </x-landing.sign-in>
                        <a href="#how" class="inline-flex items-center gap-sm rounded-lg border-[1.5px] border-primary text-primary px-lg py-[0.8rem] text-[1rem] font-semibold hover:bg-surface-container-low">
                            How it works <x-landing.icon name="arrow-down" class="size-5" />
                        </a>
                    </div>
                    <ul class="mt-xl flex flex-wrap gap-x-lg gap-y-sm text-[0.9375rem] text-on-surface-variant" data-reveal style="--d: 4">
                        <li class="flex items-center gap-xs"><x-landing.icon name="know" class="size-5 text-brand-red-ink" /> Nine areas of health</li>
                        <li class="flex items-center gap-xs"><x-landing.icon name="grow" class="size-5 text-brand-red-ink" /> A clear plan to grow</li>
                        <li class="flex items-center gap-xs"><x-landing.icon name="lock" class="size-5 text-brand-red-ink" /> Private and secure</li>
                    </ul>
                </div>
                <x-landing.ring id="hero" centre="mark" :areas="$areas" :why="$why" class="mx-auto w-full max-w-[19rem] sm:max-w-[24rem] lg:max-w-[28rem]" />
            </div>
        </section>

        {{-- Why the OHA exists --}}
        <section id="purpose" class="landing-section bg-surface">
            <div class="mx-auto max-w-[72rem] px-md py-[4.5rem] lg:py-[6rem]">
                <div class="max-w-[40rem]" data-reveal>
                    <p class="landing-eyebrow">Why it exists</p>
                    <h2 class="landing-h2">A healthy movement serves young people better.</h2>
                    <p class="mt-md text-[1.0625rem] leading-relaxed text-on-surface-variant">
                        Strong programmes need a strong organisation behind them. The OHA gives each National Movement, and the Africa Alliance of YMCAs, a common way to look honestly at that organisation and to build it up.
                    </p>
                </div>
                <div class="mt-xl grid md:grid-cols-3 gap-md">
                    @foreach ([
                        ['know', 'Know', 'An honest, shared picture of the movement’s health, area by area, on the same scale for every movement.'],
                        ['grow', 'Grow', 'An Organisational Development Plan that turns what was found into tasks, owners and deadlines.'],
                        ['shield', 'Stand strong', 'Accountable to its members, its partners and, above all, the young people the YMCA serves.'],
                    ] as $n => [$icon, $heading, $text])
                        <article class="landing-card" data-reveal style="--d: {{ $n }}">
                            <span class="landing-badge"><x-landing.icon :name="$icon" /></span>
                            <h3 class="mt-md text-[1.25rem] font-bold text-primary">{{ $heading }}</h3>
                            <p class="mt-xs text-[1rem] leading-relaxed text-on-surface-variant">{{ $text }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- The nine areas --}}
        <section id="areas" class="landing-section">
            <div class="mx-auto max-w-[72rem] px-md py-[4.5rem] lg:py-[6rem]">
                <div class="max-w-[40rem]" data-reveal>
                    <p class="landing-eyebrow">What is assessed</p>
                    <h2 class="landing-h2">The nine areas of a healthy YMCA.</h2>
                    <p class="mt-md text-[1.0625rem] leading-relaxed text-on-surface-variant">
                        Together they make up the whole organisation. A movement is only as strong as the areas it neglects, so each one is looked at in turn.
                    </p>
                </div>
                <div class="mt-xl grid lg:grid-cols-[22rem_1fr] gap-xl items-start">
                    <div class="lg:sticky lg:top-[7rem]">
                        <x-landing.ring id="areas" centre="label" :areas="$areas" label="Nine areas, one healthy YMCA" class="mx-auto w-full max-w-[16rem] sm:max-w-[20rem] lg:max-w-none" />
                    </div>
                    <ol class="grid sm:grid-cols-2 xl:grid-cols-3 gap-md">
                        @foreach ($areas as $i => $area)
                            <li class="landing-card landing-area" data-reveal data-area="{{ $i }}" data-name="{{ $area->name }}" style="--d: {{ $i % 3 }}">
                                <div class="flex items-center justify-between">
                                    <span class="landing-badge"><x-landing.icon :name="$area->code" /></span>
                                    <span class="text-[0.8125rem] font-bold tabular-nums text-outline">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                </div>
                                <h3 class="mt-md text-[1.0625rem] font-bold leading-snug text-primary">{{ $area->name }}</h3>
                                <p class="mt-xs text-[0.9375rem] leading-relaxed text-on-surface-variant">{{ $why[$area->code] ?? '' }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </section>

        {{-- How it works --}}
        <section id="how" class="landing-section bg-primary text-on-primary">
            <div class="mx-auto max-w-[72rem] px-md py-[4.5rem] lg:py-[6rem]">
                <div class="max-w-[40rem]" data-reveal>
                    <p class="landing-eyebrow !text-[#f58a91]">How it works</p>
                    <h2 class="landing-h2 !text-on-primary">A cycle of honest reflection and steady growth.</h2>
                    <p class="mt-md text-[1.0625rem] leading-relaxed text-brand-grey">
                        The OHA is not a one-off inspection. It is a regular, shared practice that helps each movement understand itself, act on what it learns, and keep improving.
                    </p>
                </div>
                <ol class="landing-steps mt-xl" data-reveal>
                    @foreach ($cycle as $n => [$icon, $heading, $text])
                        <li class="landing-step" style="--d: {{ $n }}">
                            <span class="landing-step-dot"><x-landing.icon :name="$icon" class="size-5" /></span>
                            <div>
                                <h3 class="text-[1.125rem] font-bold">{{ $heading }}</h3>
                                <p class="mt-xs text-[0.9375rem] leading-relaxed text-brand-grey">{{ $text }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- Support that matches the need --}}
        <section class="landing-section">
            <div class="mx-auto max-w-[72rem] px-md py-[4.5rem] lg:py-[6rem]">
                <div class="max-w-[40rem]" data-reveal>
                    <p class="landing-eyebrow">Support that matches the need</p>
                    <h2 class="landing-h2">One shared language for health.</h2>
                    <p class="mt-md text-[1.0625rem] leading-relaxed text-on-surface-variant">
                        Every movement is described on the same simple scale. It is not a verdict: it lets strengths be celebrated, needs be seen early, and support go where it is needed most.
                    </p>
                </div>

                <div class="landing-scale mt-xl" data-reveal aria-hidden="true">
                    @foreach ($scale as $n => $band)
                        <span style="--d: {{ $n }}; background-color: {{ $band->color }}"></span>
                    @endforeach
                </div>
                <div class="mt-xs flex justify-between gap-md text-[0.8125rem] font-semibold text-outline" aria-hidden="true">
                    <span>Needs urgent support</span><span class="text-right">A model for others</span>
                </div>

                <ul class="mt-lg grid sm:grid-cols-2 lg:grid-cols-5 gap-md">
                    @foreach ($scale as $n => $band)
                        <li class="landing-card !p-md" data-reveal style="--d: {{ $n }}; border-top: 4px solid {{ $band->color }}">
                            <div class="flex items-center gap-sm">
                                <x-landing.icon :name="$band->code" class="size-6 shrink-0" style="color: {{ $band->color }}" />
                                <h3 class="text-[1.0625rem] font-bold text-primary">{{ $band->label }}</h3>
                            </div>
                            <p class="mt-sm text-[0.9375rem] leading-relaxed text-on-surface-variant">{{ $meaning[$band->code] ?? '' }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- Trust: private, secure, and shared by choice; then sign in --}}
        <section class="landing-section bg-primary text-on-primary">
            <div class="mx-auto max-w-[72rem] px-md py-[4.5rem] lg:py-[5.5rem]">
                <div class="max-w-[42rem]" data-reveal>
                    <p class="landing-eyebrow !text-[#f58a91]">Trust</p>
                    <h2 class="landing-h2 !text-on-primary">Private by design. Shared by choice.</h2>
                    <p class="mt-md text-[1.0625rem] leading-relaxed text-brand-grey">
                        A movement’s health is its own business. The platform keeps it safe, and opens it up only where working together makes everyone stronger.
                    </p>
                </div>
                <div class="mt-xl grid md:grid-cols-3 gap-md">
                    @foreach ([
                        ['lock', 'Private', 'Each movement’s results stay with that movement and the Alliance. Nothing on this page comes from any assessment.'],
                        ['shield', 'Secure', 'Access is by invitation only, and every account and every document is protected.'],
                        ['share', 'Shared by choice', 'Movements can share good practice, documents and resources with one another, so all can learn and grow together.'],
                    ] as $n => [$icon, $heading, $text])
                        <article class="landing-trust" data-reveal style="--d: {{ $n }}">
                            <span class="landing-trust-icon"><x-landing.icon :name="$icon" /></span>
                            <div>
                                <h3 class="text-[1.125rem] font-bold">{{ $heading }}</h3>
                                <p class="mt-xs text-[0.9375rem] leading-relaxed text-brand-grey">{{ $text }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="mt-xl pt-lg border-t border-white/10 flex flex-col sm:flex-row sm:items-center gap-md" data-reveal>
                    <x-landing.sign-in label="Sign in to your dashboard" class="self-start inline-flex items-center gap-sm rounded-lg bg-surface-container-lowest text-primary px-lg py-[0.8rem] text-[1rem] font-semibold hover:bg-surface-container">
                        <x-landing.icon name="arrow-right" class="size-5" />
                    </x-landing.sign-in>
                    <p class="text-[0.9375rem] text-brand-grey">{{ \App\Demo\Sandboxes::enabled() ? 'Free to try: register in a minute. Your demo is your own: nobody else sees it.' : 'Access is by invitation only.' }}</p>
                </div>
            </div>
        </section>
    </main>

    <footer class="bg-surface-container-lowest border-t border-surface-container">
        <div class="mx-auto max-w-[72rem] px-md py-md flex flex-col sm:flex-row sm:items-center gap-sm text-[0.8125rem] text-on-surface-variant">
            <img src="{{ asset('images/aaymca-logo.png') }}" alt="{{ __('oha.app.org') }}" width="1200" height="452" class="w-[6.5rem] h-auto" loading="lazy">
            <p class="sm:ml-auto">&copy; {{ now()->year }} {{ __('oha.app.org') }} · <span class="font-semibold text-brand-red-ink">{{ __('oha.app.payoff') }}</span></p>
        </div>
    </footer>

    <script>
        (function () {
            // Each part rises into view once, as it is reached.
            var parts = document.querySelectorAll('[data-reveal]');
            if (!('IntersectionObserver' in window)) {
                parts.forEach(function (el) { el.classList.add('is-in'); });
            } else {
                var seen = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-in');
                            seen.unobserve(entry.target);
                        }
                    });
                }, { rootMargin: '0px 0px -8% 0px' });
                parts.forEach(function (el) { seen.observe(el); });
            }

            // The rings. Pointing at (or tapping) a segment lifts it out and names its area in
            // the centre; on the second ring, the area's card lights up with it, and pointing
            // at a card does the same the other way round.
            var cards = document.querySelectorAll('[data-area]');
            document.querySelectorAll('.oha-ring').forEach(function (ring) {
                var box = ring.parentNode;
                var slots = ring.querySelectorAll('[data-hit]');
                var lifts = ring.querySelectorAll('[data-lift]');
                var segments = ring.querySelectorAll('[data-seg]');
                var infos = box.querySelectorAll('[data-info]');
                var label = box.querySelector('[data-ring-label]');
                var resting = label ? label.textContent : '';
                var withCards = ring.hasAttribute('data-area-ring');
                var current = null, timer = null;

                function pick(i) {
                    clearTimeout(timer);
                    if (i === current) return;
                    current = i;
                    box.classList.toggle('is-picking', i !== null);
                    lifts.forEach(function (g) { g.classList.toggle('is-up', g.dataset.lift === i); });
                    segments.forEach(function (s) { s.classList.toggle('is-dim', i !== null && s.dataset.seg !== i); });
                    infos.forEach(function (n) { n.classList.toggle('is-shown', n.dataset.info === i); });
                    if (label) {
                        label.textContent = i === null ? resting : slots[i].dataset.name;
                        label.classList.remove('is-swapped');
                        void label.offsetWidth;
                        label.classList.add('is-swapped');
                    }
                    if (withCards) cards.forEach(function (c) { c.classList.toggle('is-lit', c.dataset.area === i); });
                }
                // A short pause before letting go, so passing over the gap between two segments does not flicker.
                function letGo() { timer = setTimeout(function () { pick(null); }, 160); }

                slots.forEach(function (slot) {
                    slot.addEventListener('pointerenter', function () { pick(slot.dataset.hit); });
                    slot.addEventListener('pointerleave', function (e) { if (e.pointerType !== 'touch') letGo(); });
                });
                // On a touch screen the area stays named until somewhere else is tapped.
                document.addEventListener('pointerdown', function (e) { if (!ring.contains(e.target) && !(withCards && e.target.closest('[data-area]'))) pick(null); });

                if (withCards) {
                    cards.forEach(function (card) {
                        card.addEventListener('pointerenter', function () { pick(card.dataset.area); });
                        card.addEventListener('pointerleave', function (e) { if (e.pointerType !== 'touch') letGo(); });
                    });
                }
            });
        })();
    </script>
</body>
</html>
