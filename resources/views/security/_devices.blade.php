{{--
    Where the person is signed in, on Security and My profile (design standard of 10 Nov
    2026): each device as a row, this one marked, each other one with a quiet "Sign out
    this device" that opens beneath it, or all of them at once. Their password is asked
    first, so someone who finds a signed-in device cannot shut its owner out. Expects $sessions.
--}}
<x-card title="Where you are signed in" padding="lg" class="min-w-0 {{ $class ?? '' }}"
        :subtitle="'Sign out any device you do not recognise, or no longer use. It can sign in again with your password. You are signed out automatically after '.config('session.lifetime').' minutes without activity.'">
    @error('password')
        <p class="text-[0.875rem] text-error" role="alert">{{ $message }}</p>
    @enderror
    <ul class="flex flex-col rounded-xl border border-[#ebe9e4]">
        @foreach ($sessions as $session)
            <li class="border-t border-[#f2f1ec] first:border-0">
                {{-- This device is a plain row; each other one opens to sign it out. --}}
                @php([$wrap, $head] = $session['current'] ? ['div', 'div'] : ['details', 'summary'])
                <{{ $wrap }} class="group/device" @if (! $session['current'] && $errors->has('password') && old('device') === $session['key']) open @endif>
                    <{{ $head }} @class(['oha-summary list-none flex flex-wrap items-center gap-sm px-md py-[12px]', 'cursor-pointer hover:bg-[#fafaf8]' => ! $session['current']])>
                        <span class="grid size-9 flex-none place-items-center rounded-[10px] bg-[#f2f1ee]" aria-hidden="true">
                            <span class="material-symbols-outlined text-[1.125rem]">{{ preg_match('/iPhone|Android/', $session['device']) ? 'smartphone' : (str_contains($session['device'], 'iPad') ? 'tablet' : 'computer') }}</span>
                        </span>
                        <span class="flex-1 min-w-[min(12rem,100%)] text-[0.875rem]">
                            <span class="block font-semibold">{{ $session['device'] }}</span>
                            <span class="block text-[0.8125rem] text-on-surface-variant">{{ $session['ip'] ?? 'Unknown address' }} · {{ $session['current'] ? 'This device, now' : 'Last active '.$session['last_active']->diffForHumans() }}</span>
                        </span>
                        @if ($session['current'])
                            <x-status-pill tone="good">This device</x-status-pill>
                        @else
                            <span class="inline-flex items-center min-h-9 rounded-[10px] border border-[#e7e5e0] bg-surface-container-lowest px-[12px] text-[0.8125rem] font-semibold group-open/device:bg-surface-container-low">Sign out this device</span>
                        @endif
                    </{{ $head }}>
                    @unless ($session['current'])
                        <form method="POST" action="{{ route('security.other-sessions') }}" class="mx-md mb-md flex flex-wrap items-end gap-sm rounded-xl bg-[#fafaf8] border border-[#ebe9e4] p-md">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="device" value="{{ $session['key'] }}">
                            <x-input name="password" label="Your password" type="password" autocomplete="current-password" required id="password-{{ $session['key'] }}" />
                            <x-button variant="secondary">Sign it out</x-button>
                        </form>
                    @endunless
                </{{ $wrap }}>
            </li>
        @endforeach
    </ul>
    @if ($sessions->count() > 2)
        <details class="text-[0.875rem]" @if ($errors->has('password') && old('device') === null && old('password_for') === 'all') open @endif>
            <summary class="cursor-pointer list-none inline-flex items-center gap-[6px] min-h-10 rounded-[10px] border border-[#ecc9c4] px-[14px] font-semibold text-critical-ink hover:bg-critical-wash">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">logout</span>Sign out every other device ({{ $sessions->count() - 1 }})
            </summary>
            <form method="POST" action="{{ route('security.other-sessions') }}" class="flex flex-wrap items-end gap-sm mt-sm rounded-xl bg-[#fafaf8] border border-[#ebe9e4] p-md">
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
</x-card>
