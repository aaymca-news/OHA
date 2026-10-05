<x-layouts.guest title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-md">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-input name="email" label="Email" type="email" :value="$request->query('email')" autocomplete="username" required />
        <x-input name="password" label="New password" type="password" autocomplete="new-password" required
                 hint="At least 12 characters, with upper- and lower-case letters and a number." />
        <x-input name="password_confirmation" label="Repeat the new password" type="password" autocomplete="new-password" required />
        <x-button>Save the new password</x-button>
    </form>
</x-layouts.guest>
