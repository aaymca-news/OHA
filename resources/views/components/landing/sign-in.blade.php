{{--
    The landing page's way in. On the live site: a link to sign in. On the demo site:
    "Start your demo" (to register, or sign in to an existing one), or "Continue your
    demo" once this browser is signed in to one. The same look in every case; the slot
    is the icon, if any.
--}}
@props(['label' => 'Sign in'])
@php
    $demo = \App\Demo\Sandboxes::enabled();
    $inDemo = $demo && \App\Demo\Sandboxes::current() !== null;
    $text = $demo ? ($inDemo ? 'Continue your demo' : 'Start your demo') : $label;
    $href = $demo && ! $inDemo ? route('demo.register') : route('login');
@endphp
<a href="{{ $href }}" {{ $attributes }}>{{ $text }} {{ $slot }}</a>
