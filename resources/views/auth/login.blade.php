@php
    // The demo site: in a visitor's own demo, "Choose who to be" (one click each); in the
    // showcase, the Super Administrator's own sign-in. Anywhere else, the usual sign-in.
    $demo = (bool) config('oha.demo');
    $showcase = $demo && \App\Demo\Sandboxes::showcase();
@endphp
<x-layouts.guest :title="$showcase ? 'Showcase sign-in' : ($demo ? 'Choose who to be' : 'Sign in')">
    @if ($demo && ! $showcase)
        {{-- The demo site only: one click per person, no password (no email sign-in here). --}}
        @include('demo.choose')
    @else
        @if ($showcase)
            <p class="text-[0.875rem] text-on-surface-variant mb-md">
                For the demo’s Super Administrator only. What you prepare here, every visitor gets a copy of when they register or start over.
            </p>
        @endif
        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-md">
            @csrf
            <x-input name="email" label="Email" type="email" autocomplete="username" required autofocus />
            <x-input name="password" label="Password" type="password" autocomplete="current-password" required />

            <label class="flex items-center gap-sm text-[0.875rem]">
                <input type="checkbox" name="remember" value="1" class="rounded border-outline-variant">
                Keep me signed in on this device
            </label>

            <x-button>Sign in</x-button>

            @unless ($showcase)
                <a href="{{ route('password.request') }}" class="text-[0.875rem] text-primary underline self-start">Forgotten your password?</a>
            @endunless
        </form>

        @unless ($showcase)
            <p class="text-[0.8125rem] text-on-surface-variant mt-md">
                Accounts are created by the AAYMCA Administrators. If you need access, ask one of them for an invitation.
            </p>
        @endunless
    @endif
</x-layouts.guest>
