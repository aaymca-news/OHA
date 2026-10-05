<x-layouts.guest title="Reset your password">
    <p class="text-[0.875rem] text-on-surface-variant mb-md">
        Enter the email address on your account and we will send you a link to choose a new password.
    </p>

    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-md">
        @csrf
        <x-input name="email" label="Email" type="email" autocomplete="username" required autofocus />
        <x-button>Email me a reset link</x-button>
        <a href="{{ route('login') }}" class="text-[0.875rem] text-primary underline self-start">Back to sign in</a>
    </form>
</x-layouts.guest>
