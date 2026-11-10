{{--
    The demo site's showcase only, for its Super Administrator: what waits for approval in
    the visitors' demos, each with a way in to approve it there (as on My Work for the
    showcase's own approvals).
--}}
@if (config('oha.demo') && \App\Demo\Sandboxes::showcase() && auth()->user()?->isSuperAdmin())
    @php($visitorApprovals = \App\Demo\DemoApprovals::byVisitor())
    @if ($visitorApprovals !== [])
        <x-card title="Waiting for approval in visitors’ demos" padding="lg">
            <ul class="flex flex-col divide-y divide-surface-container">
                @foreach ($visitorApprovals as $row)
                    <li class="flex flex-wrap items-center gap-sm py-sm">
                        <span class="min-w-0 flex-1">
                            <span class="font-semibold">{{ $row['visitor']->name }}</span>
                            <span class="block text-[0.875rem] text-on-surface-variant">{{ implode(' · ', $row['items']) }}</span>
                        </span>
                        <x-button size="sm" :href="route('demo.showcase.open', $row['visitor'])">Open their demo</x-button>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
@endif
