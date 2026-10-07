@props(['title'])
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
<body class="min-h-screen flex flex-col items-center justify-center gap-lg p-md">
    <div class="flex flex-col items-center gap-sm text-center">
        <img src="{{ asset('images/aaymca-logo.png') }}" alt="{{ __('oha.app.org') }}" width="1200" height="452" class="w-[16rem] max-w-full h-auto">
        <p class="text-[1.125rem] font-bold text-primary">{{ __('oha.app.title') }}</p>
    </div>

    <main class="w-full max-w-[28rem] bg-surface-container-lowest border-[1.5px] border-outline-variant border-t-[4px] border-t-brand-red rounded-lg p-md sm:p-lg">
        <h1 class="text-[1.25rem] font-bold text-primary mb-md">{{ $title }}</h1>
        <x-flash />
        {{ $slot }}
    </main>

    <p class="max-w-[28rem] text-center text-[0.8125rem] font-bold uppercase tracking-wider text-brand-red-ink">{{ __('oha.app.payoff') }}</p>
</body>
</html>
