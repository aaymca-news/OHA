{{--
    The Area Resource Repository (decided 27 Nov 2026): the evidence National Movements have
    shared from their Health Assessment Evidence Libraries, area by area (the nine OHA areas,
    then General), each with the movement it came from. For everyone: AAYMCA staff and every
    movement's own users. Expects $groups (code => [name, documents]), $count, $total,
    $sharers, $areas, $movements, $filters and $filtered.
--}}
@use('Illuminate\Support\Str')
<x-layouts.app :title="__('oha.nav.resource_repository')"
               subtitle="Evidence the National Movements have shared from their Health Assessment Evidence Libraries, area by area, for every movement to learn from.">

    @if ($total === 0)
        <x-panel>
            <x-empty-state icon="hub">
                Nothing has been shared yet. When a National Movement’s evidence is shared from its Health Assessment Evidence Library,
                it appears here, with the movement it was shared from.
            </x-empty-state>
        </x-panel>
    @else
        <x-panel class="!p-md">
            <form method="GET" class="flex flex-wrap items-end gap-sm" aria-label="Find shared evidence">
                <x-input name="q" type="search" label="Search" size="sm" :value="$filters['q']" placeholder="Title" wrap="grow sm:grow-0" class="sm:w-48" />
                <x-select name="area" label="Area" size="sm" placeholder="All areas" :value="$filters['area']" :options="$areas" />
                <x-select name="movement" label="Shared from" size="sm" placeholder="All movements" :value="$filters['movement']" :options="$movements->pluck('name', 'id')->all()" />
                <x-button variant="outline" size="sm">Filter</x-button>
                @if ($filtered)
                    <x-button variant="link" :href="route('resource-repository')" class="text-[0.84375rem] pb-2">Clear</x-button>
                @endif
                <p class="ml-auto pb-2 text-[0.8125rem] text-on-surface-variant">
                    {{ $total }} {{ Str::plural('document', $total) }} shared by {{ $sharers }} {{ Str::plural('National Movement', $sharers) }}
                </p>
            </form>
        </x-panel>

        @if ($count === 0)
            <x-panel>
                <x-empty-state icon="search_off">Nothing shared matches these filters.</x-empty-state>
            </x-panel>
        @else
            <div class="grid gap-md lg:grid-cols-2 items-start">
                @foreach ($groups as $code => $group)
                    <x-panel :title="$group['name']" :subtitle="$group['documents']->count().' '.Str::plural('document', $group['documents']->count())">
                        <ul class="flex flex-col">
                            @foreach ($group['documents'] as $doc)
                                <li class="flex flex-wrap items-start gap-sm py-[12px] border-t border-[#f0eeea] first:border-t-0 first:pt-0">
                                    {{-- The link carries its own icon (a file or a link). --}}
                                    <span class="flex-1 min-w-[min(12rem,100%)] flex flex-col gap-[2px]">
                                        <x-evidence-link :doc="$doc" class="font-semibold text-[0.875rem]" />
                                        <span class="pl-[calc(1.125rem+0.25rem)] text-[0.78125rem] text-on-surface-variant break-words">
                                            Shared from <span class="font-semibold text-on-surface">{{ $doc->assessment->movement->name }}</span>
                                            · <span class="whitespace-nowrap">{{ $doc->shared_at->asDay() }}</span> · <span @class(['whitespace-nowrap' => ! $doc->isLink()])>{{ $doc->describe() }}</span>
                                        </span>
                                    </span>
                                    @can('share', $doc)
                                        <x-confirm-form :action="route('evidence.share', $doc)" method="PUT"
                                                        :confirm="'Stop sharing “'.$doc->title.'”? It stays in '.$doc->assessment->movement->name.'’s library.'">
                                            <input type="hidden" name="shared" value="0">
                                            <x-button variant="secondary" size="sm">Stop sharing</x-button>
                                        </x-confirm-form>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    </x-panel>
                @endforeach
            </div>
        @endif
    @endif
</x-layouts.app>
