{{-- The demo site only: a visitor chooses a new password, from the link in their email. --}}
<x-layouts.guest title="Choose a new password">
    <form method="POST" action="{{ route('demo.password.update') }}" class="flex flex-col gap-md">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-input name="email" label="Email" type="email" :value="old('email', $email)" autocomplete="username" required />
        <x-input name="password" label="New password" type="password" autocomplete="new-password" required autofocus hint="At least 8 characters." />
        <x-input name="password_confirmation" label="Repeat the new password" type="password" autocomplete="new-password" required />
        <x-button>Save the new password</x-button>
    </form>
</x-layouts.guest>
