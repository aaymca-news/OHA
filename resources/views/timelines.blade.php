<x-layouts.app :title="__('oha.nav.timelines')"
               :subtitle="(auth()->user()->oversees() ? 'Every movement’s' : 'The movements you assess:').($tab === 'odp' ? ' ODP tasks and their deadlines, read from each ODP.' : ' deadlines for the assessments under way.')">
    <nav class="flex flex-wrap gap-xs text-[0.875rem]" aria-label="Which timeline">
        @foreach (['odp' => ['ODP tasks', 'checklist'], 'gates' => ['Assessment deadlines', 'flag']] as $key => [$label, $icon])
            <a href="{{ route('timelines', $key === 'gates' ? ['tab' => 'gates'] : []) }}" @if ($tab === $key) aria-current="page" @endif
               @class(['inline-flex items-center gap-xs px-md py-1.5 rounded border-[1.5px]', 'border-primary bg-primary text-on-primary' => $tab === $key, 'border-outline-variant hover:border-primary' => $tab !== $key])>
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">{{ $icon }}</span>{{ $label }}
            </a>
        @endforeach
    </nav>

    @if ($tab === 'odp')
        @include('timelines._odp')
    @else
        @include('timelines._gates')
    @endif
</x-layouts.app>
