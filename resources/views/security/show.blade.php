<x-layouts.app :title="__('oha.nav.security')">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md items-start">
        {{-- Password --}}
        <section class="min-w-0 bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md sm:p-lg flex flex-col gap-md">
            <h2 class="text-[1.125rem] font-bold text-primary">Change password</h2>
            <form method="POST" action="{{ route('user-password.update') }}" class="flex flex-col gap-sm">
                @csrf
                @method('PUT')
                <x-input name="current_password" label="Current password" type="password" autocomplete="current-password" required />
                <x-input name="password" label="New password" type="password" autocomplete="new-password" required
                         hint="At least 12 characters, with upper- and lower-case letters and a number." />
                <x-input name="password_confirmation" label="Repeat the new password" type="password" autocomplete="new-password" required />
                <x-button class="self-start">Change password</x-button>
            </form>
        </section>

        {{-- Sessions --}}
        <section class="min-w-0 bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md sm:p-lg flex flex-col gap-md lg:col-span-2">
            <div>
                <h2 class="text-[1.125rem] font-bold text-primary">Where you are signed in</h2>
                <p class="text-[0.875rem] text-on-surface-variant">You are signed out automatically after {{ config('session.lifetime') }} minutes without activity.</p>
            </div>
            <ul class="flex flex-col gap-xs text-[0.875rem]">
                @foreach ($sessions as $session)
                    <li class="flex flex-wrap gap-x-sm gap-y-0.5 items-baseline min-w-0">
                        <span class="font-semibold">{{ $session['ip'] ?? 'Unknown address' }}</span>
                        <span class="text-on-surface-variant min-w-0 max-w-full sm:max-w-[36rem] break-words">{{ \Illuminate\Support\Str::limit($session['agent'], 80) }}</span>
                        <span class="text-on-surface-variant">{{ $session['current'] ? 'This device' : 'Active '.$session['last_active']->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($sessions->count() > 1)
                <form method="POST" action="{{ route('security.other-sessions') }}" class="flex flex-wrap items-end gap-sm">
                    @csrf
                    @method('DELETE')
                    <x-input name="password" label="Your password" type="password" autocomplete="current-password" required />
                    <x-button variant="secondary">Sign out everywhere else</x-button>
                </form>
            @endif
        </section>
    </div>
</x-layouts.app>
