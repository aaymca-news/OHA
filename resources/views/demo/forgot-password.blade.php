{{-- The demo site only: a visitor asks for a link to choose a new password. --}}
<x-layouts.guest title="Forgotten your password?">
    <p class="text-[0.875rem] text-on-surface-variant mb-md">
        Type the email you registered your demo with, and we will send a link there to choose a new password.
    </p>

    <form method="POST" action="{{ route('demo.password.email') }}" class="flex flex-col gap-md">
        @csrf
        <x-input name="email" label="Email" type="email" autocomplete="username" required autofocus />
        <x-button>Email me a link</x-button>
        <a href="{{ route('demo.sign-in.show') }}" class="text-[0.875rem] text-primary underline self-start">Back to sign in</a>
    </form>
</x-layouts.guest>
