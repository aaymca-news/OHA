<x-layouts.guest title="Sign in">
    @if (config('oha.demo'))
        {{-- The demo site only: one click per role, no password. --}}
        <div class="flex flex-col gap-sm">
            <p class="text-[0.875rem] font-semibold">Explore the demo as:</p>
            @foreach (\App\Demo\DemoPeople::ACCOUNTS as $role => $account)
                <form method="POST" action="{{ route('demo.sign-in', $role) }}">
                    @csrf
                    <button type="submit" class="w-full text-left flex items-center gap-sm p-sm rounded-lg border-[1.5px] border-outline-variant hover:border-primary bg-surface-container-lowest">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">login</span>
                        <span class="flex flex-col">
                            <span class="text-[0.875rem] font-semibold text-primary">{{ $account['label'] }}</span>
                            <span class="text-[0.8125rem] text-on-surface-variant">{{ $account['explains'] }}</span>
                        </span>
                    </button>
                </form>
            @endforeach
            <p class="text-[0.8125rem] text-on-surface-variant mt-sm">People invited in this demo sign in below, with their email and the password they set.</p>
        </div>
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

        <a href="{{ route('password.request') }}" class="text-[0.875rem] text-primary underline self-start">Forgotten your password?</a>
    </form>

    <p class="text-[0.8125rem] text-on-surface-variant mt-md">
        Accounts are created by the AAYMCA Administrators. If you need access, ask one of them for an invitation.
    </p>
</x-layouts.guest>
