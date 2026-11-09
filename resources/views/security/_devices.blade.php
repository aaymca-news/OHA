{{--
    Where the person is signed in, on Security and My profile: each other device can be
    signed out on its own, or all of them at once. Their password is asked first, so
    someone who finds a signed-in device cannot shut its owner out. Expects $sessions.
--}}
<section class="min-w-0 bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md sm:p-lg flex flex-col gap-md {{ $class ?? '' }}">
    <div>
        <h2 class="text-[1.125rem] font-bold text-primary">Where you are signed in</h2>
        <p class="text-[0.875rem] text-on-surface-variant">
            Sign out any device you do not recognise, or no longer use. It can sign in again with your password.
            You are signed out automatically after {{ config('session.lifetime') }} minutes without activity.
        </p>
    </div>
    @error('password')
        <p class="text-[0.875rem] text-error" role="alert">{{ $message }}</p>
    @enderror
    <ul class="flex flex-col">
        @foreach ($sessions as $session)
            <li class="flex flex-col gap-xs py-sm border-t border-surface-container first:border-0">
                <div class="flex flex-wrap items-center gap-sm">
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">{{ preg_match('/iPhone|Android/', $session['device']) ? 'smartphone' : (str_contains($session['device'], 'iPad') ? 'tablet' : 'computer') }}</span>
                    <span class="flex-1 min-w-[min(12rem,100%)] text-[0.875rem]">
                        <span class="block font-semibold">{{ $session['device'] }}</span>
                        <span class="block text-[0.8125rem] text-on-surface-variant">{{ $session['ip'] ?? 'Unknown address' }} · {{ $session['current'] ? 'This device, now' : 'Last active '.$session['last_active']->diffForHumans() }}</span>
                    </span>
                    @if ($session['current'])
                        <x-chip tone="good" icon="check_circle">This device</x-chip>
                    @endif
                </div>
                @unless ($session['current'])
                    <details class="text-[0.875rem] pl-8" @if ($errors->has('password') && old('device') === $session['key']) open @endif>
                        <summary class="cursor-pointer text-primary underline w-fit">Sign out this device</summary>
                        <form method="POST" action="{{ route('security.other-sessions') }}" class="flex flex-wrap items-end gap-sm mt-xs">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="device" value="{{ $session['key'] }}">
                            <x-input name="password" label="Your password" type="password" autocomplete="current-password" required id="password-{{ $session['key'] }}" />
                            <x-button variant="secondary">Sign it out</x-button>
                        </form>
                    </details>
                @endunless
            </li>
        @endforeach
    </ul>
    @if ($sessions->count() > 2)
        <details class="text-[0.875rem] border-t border-surface-container pt-sm" @if ($errors->has('password') && old('device') === null && old('password_for') === 'all') open @endif>
            <summary class="cursor-pointer font-semibold text-primary w-fit">Sign out every other device ({{ $sessions->count() - 1 }})</summary>
            <form method="POST" action="{{ route('security.other-sessions') }}" class="flex flex-wrap items-end gap-sm mt-xs">
                @csrf
                @method('DELETE')
                <input type="hidden" name="password_for" value="all">
                <x-input name="password" label="Your password" type="password" autocomplete="current-password" required id="password-all" />
                <x-button variant="secondary">Sign out everywhere else</x-button>
            </form>
        </details>
    @elseif ($sessions->count() === 1)
        <p class="text-[0.8125rem] text-on-surface-variant">You are signed in on this device only.</p>
    @endif
</section>
