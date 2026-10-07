{{--
    Error pages (403, 404, 419, 429, 500, 503). Self-contained: styled in the page itself, so
    they show properly even when the app's own stylesheet cannot be loaded. They read on any
    screen, from a narrow phone up.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/aaymca-icon.png') }}">
    <style>
        /* The platform's own tokens (resources/css/app.css): ink, muted text, lines, the red accent and its text red. */
        :root { color-scheme: light; --ink: #231f20; --muted: #4d4d4f; --line: #c7c8ca; --accent: #ef3340; --red: #c8102e; --primary: #231f20; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; align-items: center; justify-content: center;
               gap: 1.5rem; padding: 1rem; background: #f6f6f6; color: var(--ink); line-height: 1.55;
               font-family: 'IBM Plex Sans', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; }
        img { width: 14rem; max-width: 100%; height: auto; }
        main { width: 100%; max-width: 28rem; background: #fff; border: 1.5px solid var(--line); border-top: 4px solid var(--accent); border-radius: 0.5rem; padding: 1.25rem; }
        .code { margin: 0; font-size: 0.875rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--red); }
        h1 { margin: 0.25rem 0 0.5rem; font-size: 1.375rem; line-height: 1.25; }
        p { margin: 0 0 0.75rem; font-size: 0.9375rem; color: var(--muted); overflow-wrap: anywhere; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; }
        a.button { display: inline-flex; align-items: center; min-height: 2.75rem; padding: 0.5rem 1rem; border-radius: 0.25rem; font-weight: 600;
                   font-size: 0.9375rem; text-decoration: none; border: 1.5px solid var(--primary); }
        a.primary { background: var(--primary); color: #fff; }
        a.secondary { background: #fff; color: var(--primary); }
        a:focus-visible { outline: 3px solid var(--primary); outline-offset: 2px; }
        @media (min-width: 40rem) { main { padding: 1.5rem; } }
    </style>
</head>
<body>
    <img src="{{ asset('images/aaymca-logo.png') }}" alt="{{ __('oha.app.org') }}" width="1200" height="452">
    <main>
        <p class="code">Error @yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            {{-- A missing page is answered before the sign-in is known; the dashboard asks for it if needed. --}}
            <a class="button primary" href="{{ route('dashboard') }}">Go to the dashboard</a>
            <a class="button secondary" href="javascript:history.back()">Go back</a>
        </div>
    </main>
</body>
</html>
