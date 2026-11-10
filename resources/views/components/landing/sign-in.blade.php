{{--
    The landing page's way in. On the live site: a link to sign in. On the demo site:
    "Start your own demo" (it makes the visitor's private copy), or "Continue your demo"
    once they have one. The same look in every case; the slot is the icon, if any.
--}}
@props(['label' => 'Sign in'])
@php
    $demo = \App\Demo\Sandboxes::enabled();
    $start = $demo && \App\Demo\Sandboxes::current() === null;
    $text = $start ? 'Start your own demo' : ($demo ? 'Continue your demo' : $label);
@endphp
@if ($start)
    <form method="POST" action="{{ route('demo.start') }}" class="contents">
        @csrf
        <button type="submit" {{ $attributes }}>{{ $text }} {{ $slot }}</button>
    </form>
@else
    <a href="{{ route('login') }}" {{ $attributes }}>{{ $text }} {{ $slot }}</a>
@endif
