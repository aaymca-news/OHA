{{--
    Timelines (AAYMCA staff), in the site's newer style (design standard of 10 Nov 2026):
    three tabs (decided 12 Nov 2026): the follow-ups of each movement in follow-up (their
    agreed dates), the ODP tasks (where each stands, as set at the last follow-up: tasks
    have no deadlines of their own), and the deadlines of the assessments under way. Each
    opens on counts that are also filters, then one panel per movement.
--}}
<x-layouts.app :title="__('oha.nav.timelines')"
               :subtitle="(auth()->user()->oversees() ? 'Every movement’s' : 'The movements you assess:').match ($tab) {
                   'gates' => ' deadlines for the assessments under way.',
                   'odp' => ' ODP tasks, as set at their last follow-up.',
                   default => ' follow-ups, on the dates agreed with each movement.',
               }">
    <nav class="flex gap-lg overflow-x-auto border-b border-[#e4e1db]" aria-label="Which timeline">
        @foreach (['follow-ups' => 'Follow-ups', 'odp' => 'ODP tasks', 'gates' => 'Assessment deadlines'] as $key => $label)
            <a href="{{ route('timelines', $key === 'follow-ups' ? [] : ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif
               @class(['whitespace-nowrap py-[10px] -mb-px border-b-2 text-[0.9375rem]',
                       'border-on-surface font-semibold text-on-surface' => $tab === $key,
                       'border-transparent text-on-surface-variant hover:text-on-surface' => $tab !== $key])>{{ $label }}</a>
        @endforeach
    </nav>

    @switch ($tab)
        @case('follow-ups')
            @include('timelines._follow-ups')
            @break
        @case('odp')
            @include('timelines._odp')
            @break
        @default
            @include('timelines._gates')
    @endswitch
</x-layouts.app>
