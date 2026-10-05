<x-layouts.guest title="Confirm your password">
    <p class="text-[0.875rem] text-on-surface-variant mb-md">
        This changes your account's security, so please confirm your password first.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="flex flex-col gap-md">
        @csrf
        <x-input name="password" label="Password" type="password" autocomplete="current-password" required autofocus />
        <x-button>Confirm</x-button>
    </form>
</x-layouts.guest>
