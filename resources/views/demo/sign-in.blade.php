{{-- The demo site only: a visitor signs in to their own demo, on any computer. --}}
<x-layouts.guest title="Sign in to your demo">
    <form method="POST" action="{{ route('demo.sign-in.store') }}" class="flex flex-col gap-md">
        @csrf
        <x-input name="email" label="Email" type="email" autocomplete="username" required autofocus />
        <x-input name="password" label="Password" type="password" autocomplete="current-password" required />
        <x-button>Sign in</x-button>
        <a href="{{ route('demo.password.request') }}" class="text-[0.875rem] text-primary underline self-start">Forgotten your password?</a>
    </form>

    <p class="text-[0.8125rem] text-on-surface-variant mt-md">
        No demo yet? <a href="{{ route('demo.register') }}" class="text-primary underline">Start your demo</a>.
    </p>
</x-layouts.guest>
