{{--
    The demo site only: what this is, that only made-up data belongs here, and the
    visitor’s own private copy: its private link to come back to it, or start over (the landing page starts one).
--}}
@if (config('oha.demo'))
    @php
        $sandboxes = \App\Demo\Sandboxes::enabled();
        $mine = \App\Demo\Sandboxes::current();
    @endphp
    <div role="note" class="w-full bg-warning-wash text-warning-ink border-b-[1.5px] border-band-developing px-md py-xs text-[0.8125rem]"
         x-data="{ copied: false }">
        <div class="flex flex-wrap items-center justify-center gap-x-md gap-y-xs text-center">
            <span class="font-semibold">
                <span class="material-symbols-outlined text-[1rem] align-middle" aria-hidden="true">science</span>
                Demo site, for trying the platform. Use made-up data only: never upload a real movement’s forms, reports or documents.
            </span>
            @if ($sandboxes && $mine)
                <span>Your demo is private: nobody else sees it.</span>
                <button type="button" class="underline"
                        x-on:click="navigator.clipboard.writeText(@js(route('demo.resume', $mine))); copied = true; setTimeout(() => copied = false, 2500)">
                    <span x-show="! copied">Copy your private demo link</span><span x-show="copied" x-cloak>Copied: open it on any computer to come back</span>
                </button>
                <form method="POST" action="{{ route('demo.start-over') }}" class="inline"
                      x-on:submit="if (! confirm('Throw away everything you did in this demo and start again with a clean one?')) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="underline">Start over</button>
                </form>
            @endif
        </div>
    </div>
@endif
