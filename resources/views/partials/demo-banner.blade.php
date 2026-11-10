{{--
    The demo site only: what this is, that only made-up data belongs here, and, for a
    signed-in visitor, whose demo this is: choose who to be, start over, sign out of the
    demo, or delete it and the account. The Super Administrator visiting a visitor's demo
    is told so, and goes back to the showcase from here.
--}}
@if (config('oha.demo'))
    @php
        $visitor = \App\Demo\Sandboxes::enabled() ? \App\Demo\DemoVisitor::forSandbox(\App\Demo\Sandboxes::current()) : null;
    @endphp
    <div role="note" class="w-full bg-warning-wash text-warning-ink border-b-[1.5px] border-band-developing px-md py-xs text-[0.8125rem]">
        <div class="flex flex-wrap items-center justify-center gap-x-md gap-y-xs text-center">
            <span class="font-semibold">
                <span class="material-symbols-outlined text-[1rem] align-middle" aria-hidden="true">science</span>
                Demo site, for trying the platform. Use made-up data only: never upload a real movement’s forms, reports or documents.
            </span>
            @if (\App\Demo\Sandboxes::showcase())
                <span><strong>Showcase:</strong> what you do here, every visitor gets a copy of when they register or start over.</span>
                <form method="POST" action="{{ route('demo.showcase.leave') }}" class="inline">
                    @csrf
                    <button type="submit" class="underline">Leave the showcase</button>
                </form>
            @endif
            @if ($visitor && auth()->user()?->isSuperAdmin())
                {{-- The Super Administrator visiting a visitor's demo, to act on what was sent for approval. --}}
                <span>You are in <strong>{{ $visitor->name }}</strong>’s demo, as the Super Administrator.</span>
                <form method="POST" action="{{ route('demo.showcase.back') }}" class="inline">
                    @csrf
                    <button type="submit" class="underline font-semibold">Back to the showcase</button>
                </form>
            @elseif ($visitor)
                <span>Signed in to your demo as <strong>{{ $visitor->name }}</strong>.</span>
                @if (\App\Demo\Sandboxes::showcaseChangedSince((string) $visitor->sandbox_key))
                    @if (\App\Demo\Sandboxes::hasOwnWork((string) $visitor->sandbox_key))
                        <span class="font-semibold">New work was added to the showcase since your copy: “Start over” to get it (it replaces what you did here).</span>
                    @else
                        <span class="font-semibold">New work was added to the showcase: it comes into your demo when you next choose who to be.</span>
                    @endif
                @endif
                @auth
                    <form method="POST" action="{{ route('demo.choose') }}" class="inline">
                        @csrf
                        <button type="submit" class="underline">Choose who to be</button>
                    </form>
                @endauth
                <form method="POST" action="{{ route('demo.start-over') }}" class="inline"
                      x-data x-on:submit="if (! confirm('Throw away everything you did in this demo and start again from the latest showcase? Your account stays.')) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="underline">Start over</button>
                </form>
                <form method="POST" action="{{ route('demo.sign-out') }}" class="inline">
                    @csrf
                    <button type="submit" class="underline">Sign out of your demo</button>
                </form>
                <form method="POST" action="{{ route('demo.destroy') }}" class="inline"
                      x-data x-on:submit="if (! confirm('Delete your demo and your account? Everything you did is lost, and this cannot be undone.')) $event.preventDefault()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="underline">Delete my demo and account</button>
                </form>
            @endif
        </div>
    </div>
@endif
