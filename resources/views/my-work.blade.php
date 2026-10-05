<x-layouts.app :title="__('oha.work.title')" :subtitle="__('oha.work.subtitle')">
    @php($counts = collect($groups)->mapWithKeys(fn ($g) => [$g['key'] => count($g['items'])]))
    <nav class="flex flex-wrap gap-xs" aria-label="Work groups">
        @foreach ($groups as $group)
            <a href="{{ route('my-work', ['group' => $group['key']]) }}" @if ($active === $group['key']) aria-current="page" @endif
               @class([
                   'px-md py-1.5 rounded-full border-[1.5px] text-[0.875rem]',
                   'border-primary bg-primary text-on-primary font-semibold' => $active === $group['key'],
                   'border-outline-variant hover:border-primary' => $active !== $group['key'],
               ])>
                {{ __('oha.work.groups.'.$group['key']) }} <span class="tabular-nums">({{ $counts[$group['key']] }})</span>
            </a>
        @endforeach
    </nav>

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
