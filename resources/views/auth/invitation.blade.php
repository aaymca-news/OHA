<x-layouts.guest title="Set your password">
    <p class="text-[0.875rem] text-on-surface-variant mb-md">
        Welcome. Choose a password to finish setting up your account.
    </p>

    <form method="POST" action="{{ route('invitation.store') }}" class="flex flex-col gap-md">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-input name="email" label="Email" type="email" :value="$email" autocomplete="username" required />
        <x-input name="password" label="Password" type="password" autocomplete="new-password" required
                 hint="At least 12 characters, with upper- and lower-case letters and a number." />
        <x-input name="password_confirmation" label="Repeat the password" type="password" autocomplete="new-password" required />
        <x-button>Set password and sign in</x-button>
    </form>
</x-layouts.guest>
