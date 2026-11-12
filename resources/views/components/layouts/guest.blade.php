@props(['title'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Only the public front page is for search engines. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/aaymca-icon.png') }}">
    @include('partials.display-preferences')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- The demo site's strip (none on the live site) runs along the top; the page is centred beneath it. --}}
<body class="min-h-screen flex flex-col">
    @include('partials.demo-banner')
    <div class="flex-1 flex flex-col items-center justify-center gap-lg p-md">
    <div class="flex flex-col items-center gap-sm text-center">
        <a href="{{ route('home') }}" title="About the Organisational Health Assessment">
            <img src="{{ asset('images/aaymca-logo.png') }}" alt="{{ __('oha.app.org') }}" width="1200" height="452" class="w-[16rem] max-w-full h-auto">
        </a>
        <p class="text-[1.125rem] font-bold text-on-surface">{{ __('oha.app.title') }}</p>
    </div>

    <main class="w-full max-w-[28rem] bg-surface-container-lowest border border-[#e7e5e0] border-t-[3px] border-t-brand-red rounded-2xl shadow-[0_8px_30px_rgba(20,20,20,0.06)] p-lg sm:p-[32px]">
        <h1 class="text-[1.375rem] font-bold text-on-surface mb-md">{{ $title }}</h1>
        <x-flash />
        {{ $slot }}
    </main>

    <p class="max-w-[28rem] text-center text-[0.8125rem] font-bold uppercase tracking-wider text-brand-red-ink">{{ __('oha.app.payoff') }}</p>
    </div>
</body>
</html>
