<x-layouts.app :title="__('oha.nav.timelines')"
               :subtitle="(auth()->user()->oversees() ? 'Every movement’s' : 'The movements you assess:').($tab === 'odp' ? ' ODP tasks and their deadlines, read from each ODP.' : ' deadlines for the assessments under way.')">
    <x-segmented label="Which timeline">
        @foreach (['odp' => ['ODP tasks', 'checklist'], 'gates' => ['Assessment deadlines', 'flag']] as $key => [$label, $icon])
            <x-segmented.link :href="route('timelines', $key === 'gates' ? ['tab' => 'gates'] : [])" :active="$tab === $key" :icon="$icon">{{ $label }}</x-segmented.link>
        @endforeach
    </x-segmented>

    @if ($tab === 'odp')
        @include('timelines._odp')
    @else
        @include('timelines._gates')
    @endif
</x-layouts.app>
