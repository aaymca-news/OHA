{{--
    The demo site only: what this is, that only made-up data belongs here, and, for a
    signed-in visitor: choose who to be, sign out, or delete their account. In one shared
    demo (config oha.demo_shared) everyone works in the same demo; otherwise each visitor
    has a private copy (start over, news of the showcase's work), and the Super
    Administrator visiting one is told so and goes back to the showcase from here.
--}}
@if (config('oha.demo'))
    @php
        $visitor = \App\Demo\Sandboxes::enabled() ? \App\Demo\DemoVisitor::forSandbox(\App\Demo\Sandboxes::current()) : null;
        $shared = \App\Demo\Sandboxes::shared();
    @endphp
    <div role="note" class="w-full bg-warning-wash text-warning-ink border-b-[1.5px] border-band-developing px-md py-xs text-[0.8125rem]">
        <div class="flex flex-wrap items-center justify-center gap-x-md gap-y-xs text-center">
            <span class="font-semibold">
                <span class="material-symbols-outlined text-[1rem] align-middle" aria-hidden="true">science</span>
                Demo site, for trying the platform. Use made-up data only: never upload a real movement’s forms, reports or documents.
            </span>
            @if (\App\Demo\Sandboxes::showcase())
                @if ($shared)
                    <span><strong>Showcase:</strong> you and every visitor work in this one demo: what anyone does, everyone sees.</span>
                @else
                    <span><strong>Showcase:</strong> what you do here, every visitor gets a copy of when they register or start over.</span>
                    @if (auth()->user()?->isSuperAdmin() && ($waiting = \App\Demo\DemoApprovals::count()) > 0)
                        <a href="{{ route('admin.users.index', ['show' => 'visitors']) }}" class="underline font-semibold">
                            {{ $waiting }} {{ $waiting === 1 ? 'item is' : 'items are' }} waiting for approval in visitors’ demos
                        </a>
                    @endif
                @endif
                <form method="POST" action="{{ route('demo.showcase.leave') }}" class="inline">
                    @csrf
                    <button type="submit" class="underline">Leave the showcase</button>
                </form>
            @endif
            @if ($visitor && ! $shared && auth()->user()?->isSuperAdmin())
                {{-- The Super Administrator visiting a visitor's demo, to act on what was sent for approval. --}}
                <span>You are in <strong>{{ $visitor->name }}</strong>’s demo, as the Super Administrator.</span>
                <form method="POST" action="{{ route('demo.showcase.back') }}" class="inline">
                    @csrf
                    <button type="submit" class="underline font-semibold">Back to the showcase</button>
                </form>
            @elseif ($visitor)
                @if ($shared)
                    <span>Signed in to the demo as <strong>{{ $visitor->name }}</strong>. Everyone shares this demo: what you do, others see.</span>
                @else
                    <span>Signed in to your demo as <strong>{{ $visitor->name }}</strong>.</span>
                    @if (\App\Demo\Sandboxes::showcaseChangedSince((string) $visitor->sandbox_key))
                        @if (\App\Demo\Sandboxes::hasOwnWork((string) $visitor->sandbox_key))
                            <span class="font-semibold">New work was added to the showcase since your copy: “Start over” to get it (it replaces what you did here).</span>
                        @else
                            <span class="font-semibold">New work was added to the showcase: it comes into your demo when you next choose who to be.</span>
                        @endif
                    @endif
                @endif
                @auth
                    <form method="POST" action="{{ route('demo.choose') }}" class="inline">
                        @csrf
                        <button type="submit" class="underline">Choose who to be</button>
                    </form>
                @endauth
                @unless ($shared)
                    <form method="POST" action="{{ route('demo.start-over') }}" class="inline"
                          x-data x-on:submit="if (! confirm('Throw away everything you did in this demo and start again from the latest showcase? Your account stays.')) $event.preventDefault()">
                        @csrf
                        <button type="submit" class="underline">Start over</button>
                    </form>
                @endunless
                <form method="POST" action="{{ route('demo.sign-out') }}" class="inline">
                    @csrf
                    <button type="submit" class="underline">Sign out of the demo</button>
                </form>
                <form method="POST" action="{{ route('demo.destroy') }}" class="inline"
                      x-data x-on:submit="if (! confirm(@js($shared ? 'Delete your account? The work done in the shared demo stays there.' : 'Delete your demo and your account? Everything you did is lost, and this cannot be undone.'))) $event.preventDefault()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="underline">{{ $shared ? 'Delete my account' : 'Delete my demo and account' }}</button>
                </form>
            @endif
        </div>
    </div>
@endif
