<x-layouts.guest title="Sign in">
    <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-md">
        @csrf
        <x-input name="email" label="Email" type="email" autocomplete="username" required autofocus />
        <x-input name="password" label="Password" type="password" autocomplete="current-password" required />

        <label class="flex items-center gap-sm text-[0.875rem]">
            <input type="checkbox" name="remember" value="1" class="rounded border-outline-variant">
            Keep me signed in on this device
        </label>

        <x-button>Sign in</x-button>

        <a href="{{ route('password.request') }}" class="text-[0.875rem] text-primary underline self-start">Forgotten your password?</a>
    </form>

    <p class="text-[0.8125rem] text-on-surface-variant mt-md">
        Accounts are created by the AAYMCA Administrators. If you need access, ask one of them for an invitation.
    </p>
</x-layouts.guest>
