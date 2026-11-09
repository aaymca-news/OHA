{{--
    The component gallery: every shared component, in each variant, on one page. Local only
    (see routes/web.php). When you add or change a component, show it here too.
--}}
@php
    $tones = ['good', 'info', 'warning', 'serious', 'critical', 'neutral'];
@endphp
<x-layouts.app title="Components" subtitle="Every shared component on one page. Build new pages from these; add a component here when you make one.">
    <x-card title="Buttons" subtitle="x-button: variant, size, icon, href (renders a link).">
        <div class="flex flex-wrap items-center gap-sm">
            <x-button type="button">Primary</x-button>
            <x-button type="button" variant="secondary">Secondary</x-button>
            <x-button type="button" variant="outline">Outline</x-button>
            <x-button type="button" variant="danger">Danger</x-button>
            <x-button type="button" icon="upload">With icon</x-button>
            <x-button type="button" size="sm">Small</x-button>
            <x-button type="button" size="sm" variant="outline" icon="open_in_new">Small outline</x-button>
            <x-button href="#" icon="arrow_forward">A link as a button</x-button>
            <x-button type="button" variant="link" icon="edit">Link</x-button>
            <x-button type="button" variant="link-danger" icon="delete">Link (danger)</x-button>
            <x-button type="button" disabled>Disabled</x-button>
        </div>
    </x-card>

    <x-card title="Form fields" subtitle="x-input, x-select, x-textarea (each in an x-field: label, hint, error). size sm for filter bars.">
        <div class="grid sm:grid-cols-3 gap-md">
            <x-input name="demo_text" label="Text" hint="A hint under the box." />
            <x-select name="demo_select" label="Dropdown" placeholder="Choose…" :options="['a' => 'First', 'b' => 'Second']" />
            <x-input name="demo_password" label="Password" type="password" />
            <x-textarea name="demo_area" label="Text area" rows="3" wrap="sm:col-span-3" class="w-full" />
        </div>
        <div class="flex flex-wrap items-end gap-sm">
            <x-input name="demo_q" type="search" label="Search" size="sm" placeholder="Compact" />
            <x-select name="demo_band" label="Band" size="sm" placeholder="All" :options="['strong' => 'Strong', 'developing' => 'Developing']" />
            <x-button type="button" variant="secondary">Filter</x-button>
        </div>
        <x-field label="Anything else" hint="x-field wraps any control.">
            <span class="flex gap-md"><label class="flex items-center gap-xs"><input type="checkbox"> One</label><label class="flex items-center gap-xs"><input type="checkbox"> Two</label></span>
        </x-field>
    </x-card>

    <x-card title="Choices" subtitle="x-segmented with x-segmented.link: pills (default) or joined.">
        <x-segmented label="Demo filter">
            <x-segmented.link href="#" active>Current <span class="tabular-nums">(4)</span></x-segmented.link>
            <x-segmented.link href="#">Other</x-segmented.link>
            <x-segmented.link href="#" icon="star">With icon</x-segmented.link>
        </x-segmented>
        <x-segmented label="Demo view" variant="joined" class="self-start">
            <x-segmented.link href="#" icon="grid_view" active>Cards</x-segmented.link>
            <x-segmented.link href="#" icon="table_rows">Table</x-segmented.link>
        </x-segmented>
    </x-card>

    <x-card title="Callouts" subtitle="x-callout: a tone, an optional title and icon; alert for problems.">
        @foreach ($tones as $tone)
            <x-callout :tone="$tone" :title="ucfirst($tone)">The message, in the {{ $tone }} tone.</x-callout>
        @endforeach
        <x-callout tone="serious" title="A problem to act on" alert icon="link_off">With alert: a border, and read out at once.</x-callout>
    </x-card>

    <x-card title="Chips" subtitle="x-chip (tone, icon), x-band-chip, x-state-chip, x-due-chip.">
        <div class="flex flex-wrap gap-xs">
            @foreach ($tones as $tone)
                <x-chip :tone="$tone" icon="circle">{{ ucfirst($tone) }}</x-chip>
            @endforeach
            <x-band-chip code="strong" label="Strong" icon="check_circle" />
            <x-band-chip under-way />
            <x-due-chip :days="3" />
            <x-due-chip :days="-2" />
        </div>
    </x-card>

    <x-table caption="A table (x-table)">
        <x-slot:head><tr><th>Movement</th><th>Band</th><th class="text-right">Score</th></tr></x-slot:head>
        <tr class="border-t border-surface-container"><td>Zambia YMCA</td><td><x-band-chip code="developing" label="Developing" icon="trending_up" /></td><td class="text-right tabular-nums">69.0%</td></tr>
        <tr class="border-t border-surface-container"><td>Ghana YMCA</td><td><x-band-chip code="strong" label="Strong" icon="check_circle" /></td><td class="text-right tabular-nums">78.5%</td></tr>
    </x-table>

    <div class="grid sm:grid-cols-2 gap-md items-start">
        <x-card title="A card" subtitle="x-card: title, subtitle, actions slot, padding.">
            <x-slot:actions><x-button type="button" size="sm" variant="outline">Action</x-button></x-slot:actions>
            <p class="text-[0.875rem]">The content.</p>
        </x-card>
        <x-card.details title="A folding card" subtitle="x-card.details: opens on a click.">
            <p class="text-[0.875rem]">Shown once opened.</p>
        </x-card.details>
    </div>

    <x-card title="Empty, confirm, stat" subtitle="x-empty-state, x-confirm-form, x-stat-tile.">
        <x-empty-state icon="task_alt">Nothing here yet.</x-empty-state>
        <x-confirm-form action="#" confirm="Delete this? It cannot be undone." class="self-start">
            <x-button variant="link-danger" icon="delete">Delete (asks first)</x-button>
        </x-confirm-form>
        <div class="grid sm:grid-cols-3 gap-md">
            <x-stat-tile label="Health score" value="69.0" unit="%"><x-slot:sub><x-band-chip code="developing" label="Developing" icon="trending_up" /></x-slot:sub></x-stat-tile>
        </div>
    </x-card>
</x-layouts.app>
