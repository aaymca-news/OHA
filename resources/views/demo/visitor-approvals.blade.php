{{--
    The demo site's showcase only, for its Super Administrator: what waits for approval in
    the visitors' demos, each with a way in to approve it there (as on My Work for the
    showcase's own approvals).
--}}
@if (config('oha.demo') && \App\Demo\Sandboxes::showcase() && auth()->user()?->isSuperAdmin())
    @php($visitorApprovals = \App\Demo\DemoApprovals::byVisitor())
    @if ($visitorApprovals !== [])
        <x-panel flush title="Waiting for approval in visitors’ demos">
            <ul class="flex flex-col">
                @foreach ($visitorApprovals as $row)
                    <li class="flex flex-wrap items-center gap-x-md gap-y-sm px-lg py-md border-t border-[#f2f1ec] first:border-0 hover:bg-[#fafaf8]">
                        <span class="min-w-0 flex-[999_1_16rem] flex flex-col gap-[2px]">
                            <span class="text-[0.9375rem] font-semibold">{{ $row['visitor']->name }}</span>
                            <span class="text-[0.8125rem] text-on-surface-variant">{{ implode(' · ', $row['items']) }}</span>
                        </span>
                        <x-status-pill tone="warning">Awaiting approval</x-status-pill>
                        <x-row-action primary :href="route('demo.showcase.open', $row['visitor'])">Open their demo</x-row-action>
                    </li>
                @endforeach
            </ul>
        </x-panel>
    @endif
@endif
