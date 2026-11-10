{{-- The demo site only: a visitor registers and gets a demo of their own. --}}
<x-layouts.guest title="Start your demo">
    <p class="text-[0.875rem] text-on-surface-variant mb-md">
        Your own demo of the platform: every role is ready for you, and what you do, nobody else sees.
        Your account keeps it, so you can come back to it on any computer.
    </p>

    <form method="POST" action="{{ route('demo.register.store') }}" class="flex flex-col gap-md">
        @csrf
        <x-input name="name" label="Your name" autocomplete="name" required autofocus />
        <x-input name="email" label="Email" type="email" autocomplete="username" required
                 hint="You sign in with it, and a link to choose a new password is sent to it if you forget." />
        <x-input name="password" label="Password" type="password" autocomplete="new-password" required hint="At least 8 characters." />
        <x-input name="password_confirmation" label="Repeat the password" type="password" autocomplete="new-password" required />
        <x-button>Start my demo</x-button>
        <a href="{{ route('demo.sign-in.show') }}" class="text-[0.875rem] text-primary underline self-start">Already have a demo? Sign in</a>
    </form>

    <p class="text-[0.8125rem] text-on-surface-variant mt-md">
        Use made-up data only. Your email is used only to sign in to your demo and to reset your password.
        A demo unused for {{ (int) config('oha.demo_sandbox_days') }} days is deleted, with its account.
    </p>
</x-layouts.guest>
