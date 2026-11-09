<x-layouts.app :title="__('oha.work.title')" :subtitle="__('oha.work.subtitle')">
    @php($counts = collect($groups)->mapWithKeys(fn ($g) => [$g['key'] => count($g['items'])]))
    <x-segmented label="Work groups">
        @foreach ($groups as $group)
            <x-segmented.link :href="route('my-work', ['group' => $group['key']])" :active="$active === $group['key']">
                {{ __('oha.work.groups.'.$group['key']) }} <span class="tabular-nums">({{ $counts[$group['key']] }})</span>
            </x-segmented.link>
        @endforeach
    </x-segmented>

    @forelse ($groups as $group)
        @continue($group['key'] !== $active)
        <x-card :title="__('oha.work.groups.'.$group['key'])">
            @if ($group['items'] === [])
                <x-empty-state icon="task_alt">{{ __('oha.work.empty') }}</x-empty-state>
            @else
                <ul class="flex flex-col gap-sm">
                    @foreach ($group['items'] as $entry)
                        @include('partials.work-row', ['entry' => $entry, 'group' => $group['key']])
                    @endforeach
                </ul>
            @endif
        </x-card>
    @empty
        <x-empty-state icon="task_alt">Nothing is waiting on you.</x-empty-state>
    @endforelse
</x-layouts.app>
