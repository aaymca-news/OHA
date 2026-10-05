{{-- What the latest approved OHA form says about the movement, before anyone opens the form. Expects $profile, $latest, $movement. --}}
@use('App\Oha\Profile')
@use('App\Support\Viz')
@php
    $f = $profile['finance'];
    $b = $profile['board'];
    $p = $profile['people'];
    $id = $profile['identity'];
    $labelled = fn (array $labels, ?array $values) => $values === null ? [] : collect($labels)->mapWithKeys(fn ($label, $code) => [$label => $values[$code] ?? null])->all();
    $categoryNames = \App\Models\Category::query()->pluck('name', 'code');
@endphp

<section class="flex flex-col gap-md" aria-labelledby="glance">
    <div>
        <h2 id="glance" class="text-[1.125rem] font-bold text-primary">At a glance</h2>
        <p class="text-[0.8125rem] text-on-surface-variant">From the approved {{ $latest->period_label }} OHA form. Money is in the movement’s own currency{{ $f['currency'] ? ' ('.$f['currency'].')' : '' }}.</p>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-md">
        <x-stat-tile label="People reached" :value="Viz::compact($profile['beneficiaries'])"><x-slot:sub>a year (Q518)</x-slot:sub></x-stat-tile>
        <x-stat-tile label="Staff" :value="Viz::number($p['staff'])"><x-slot:sub>{{ $p['staff_under_30'] !== null ? Viz::number($p['staff_under_30']).' under 30' : 'Q1008' }}</x-slot:sub></x-stat-tile>
        <x-stat-tile label="Volunteers" :value="Viz::number($p['volunteers'])"><x-slot:sub>{{ $p['enough_volunteers'] === false ? 'Not enough, says the form' : 'Q1013' }}</x-slot:sub></x-stat-tile>
        <x-stat-tile :label="$id['structure'] === 'Federative Structure' ? 'Member YMCAs' : 'Branches'" :value="Viz::number($id['structure'] === 'Federative Structure' ? $id['member_organisations'] : $id['branches'])">
            <x-slot:sub>{{ $id['structure'] ?? 'Structure not given' }}</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Board members" :value="Viz::number($b['size'])"><x-slot:sub>{{ $b['min'] !== null && $b['max'] !== null ? 'Constitution allows '.Viz::number($b['min']).'–'.Viz::number($b['max']) : 'Q315–Q317' }}</x-slot:sub></x-stat-tile>
        <x-stat-tile label="Policies in place" :value="count(array_filter($profile['policies']))" :unit="' / '.count($profile['policies'])"><x-slot:sub>Q412–Q430</x-slot:sub></x-stat-tile>
    </div>

    <div class="grid lg:grid-cols-2 gap-md items-start">
        <x-card title="Money" subtitle="Last financial year (Q209–Q248).">
            <dl class="grid grid-cols-3 gap-md">
                @foreach ([['Income', $f['income']], ['Expenditure', $f['expenditure']], [$f['balance'] !== null && $f['balance'] < 0 ? 'Deficit' : 'Surplus', $f['balance']]] as [$label, $value])
                    <div>
                        <dt class="text-[0.8125rem] text-on-surface-variant">{{ $label }}</dt>
                        <dd class="text-[1.25rem] font-bold text-primary leading-tight">{{ Viz::compact($value) }}</dd>
                    </div>
                @endforeach
            </dl>
            <dl class="grid grid-cols-2 gap-x-md gap-y-xs text-[0.8125rem]">
                <dt class="text-on-surface-variant">Operating margin</dt><dd class="font-semibold">{{ $f['margin_pct'] !== null ? ($f['margin_pct'] > 0 ? '+' : '').Viz::number($f['margin_pct'], 1).'%' : '—' }}</dd>
                <dt class="text-on-surface-variant">Liabilities ÷ assets</dt><dd class="font-semibold">{{ $f['liability_ratio'] !== null ? Viz::number($f['liability_ratio'], 2).'×' : '—' }}{{ $f['liability_ratio'] !== null && $f['liability_ratio'] > 1 ? ' · more owed than owned' : '' }}</dd>
                <dt class="text-on-surface-variant">Cash reserve</dt><dd class="font-semibold">{{ $f['reserve_months'] !== null ? Viz::number($f['reserve_months']).' months' : 'None reported' }}</dd>
                <dt class="text-on-surface-variant">Outstanding debt</dt><dd class="font-semibold">{{ $f['has_debt'] === true ? Viz::compact($f['debt']) : ($f['has_debt'] === false ? 'None' : '—') }}</dd>
                <dt class="text-on-surface-variant">Can cover 2 years of costs</dt><dd class="font-semibold">{{ $f['covers_two_years'] === null ? '—' : ($f['covers_two_years'] ? 'Yes' : 'No') }}</dd>
                <dt class="text-on-surface-variant">Fundraising cost</dt><dd class="font-semibold">{{ $f['fundraising_pct'] !== null ? Viz::number($f['fundraising_pct'], 1).'% of income' : '—' }}</dd>
            </dl>
            <div class="flex flex-col gap-xs">
                <x-bar :value="$profile['international_pct'] ?? 0" :label="$profile['international_pct'] !== null ? Viz::number($profile['international_pct'], 1).'% international' : 'International share not given'"
                       :tone="($profile['international_pct'] ?? 0) > 75 ? 'serious' : 'primary'" wide />
                <x-bar :value="$profile['restricted_pct'] ?? 0" :label="$profile['restricted_pct'] !== null ? Viz::number($profile['restricted_pct'], 1).'% restricted' : 'Restricted share not given'" wide />
                @if (($profile['international_pct'] ?? 0) > 75)
                    <p class="text-[0.8125rem] text-serious-ink">Above the form’s 75% line for reliance on international sources.</p>
                @endif
            </div>
        </x-card>

        <x-card title="Funding and spending" subtitle="Shares as the movement entered them.">
            @if ($profile['income_mix'])
                <x-viz.stacked caption="Where the money comes from" :segments="collect(Profile::INCOME_GROUPS)->map(fn ($g, $k) => ['label' => $g['label'], 'value' => $profile['income_mix'][$k]])->values()->all()" />
            @else
                <p class="text-[0.8125rem] text-on-surface-variant">Income by source not given.</p>
            @endif
            @if ($profile['expense_mix'])
                <x-viz.stacked caption="Where the money goes" :segments="collect(Profile::EXPENSE_GROUPS)->map(fn ($g, $k) => ['label' => $g['label'], 'value' => $profile['expense_mix'][$k]])->values()->all()" />
            @else
                <p class="text-[0.8125rem] text-on-surface-variant">Expenditure by type not given.</p>
            @endif
        </x-card>

        <x-card title="Board" subtitle="Current members (Q315–Q319).">
            @if ($b['size'])
                <x-viz.stacked :segments="array_values(array_filter([['label' => 'Women', 'value' => $b['women'] ?? 0], ['label' => 'Men', 'value' => $b['men'] ?? 0], ($b['non_binary'] ?? 0) > 0 ? ['label' => 'Non-binary', 'value' => $b['non_binary']] : null]))" />
                <dl class="grid grid-cols-2 gap-x-md gap-y-xs text-[0.8125rem]">
                    <dt class="text-on-surface-variant">Under 30</dt><dd class="font-semibold">{{ Viz::number($b['under_30']) }}{{ $b['under_30'] !== null ? ' ('.round($b['under_30'] * 100 / $b['size']).'%)' : '' }}</dd>
                    <dt class="text-on-surface-variant">First term</dt><dd class="font-semibold">{{ Viz::number($b['first_term']) }}</dd>
                    <dt class="text-on-surface-variant">Term length</dt><dd class="font-semibold">{{ $b['term_years'] !== null ? Viz::number($b['term_years']).' years' : '—' }}</dd>
                    <dt class="text-on-surface-variant">Meetings last year</dt><dd class="font-semibold">{{ $b['meetings'] ?? '—' }}</dd>
                </dl>
            @else
                <p class="text-[0.8125rem] text-on-surface-variant">Board make-up not given.</p>
            @endif
            <x-viz.checklist :items="$labelled(Profile::GOVERNANCE_PRACTICES, $profile['governance'])" columns="grid-cols-1 sm:grid-cols-2" />
        </x-card>

        <x-card title="Policies" subtitle="Do the following exist and are they applied? (Q412–Q430)">
            <x-viz.checklist :items="$labelled(Profile::POLICIES, $profile['policies'])" />
        </x-card>

        <x-card title="Strategy and Vision 2030" subtitle="Strategic Plan (Q610–Q635).">
            <x-viz.checklist :items="$labelled(Profile::PRACTICES, $profile['practices'])" />
            @if ($profile['vision_2030'] !== null)
                <div class="border-t border-surface-container pt-sm">
                    <p class="text-[0.8125rem] font-semibold mb-xs">Vision 2030 components in the plan</p>
                    <x-viz.checklist :items="$labelled(Profile::VISION_2030, $profile['vision_2030'])" />
                </div>
            @endif
            <div class="border-t border-surface-container pt-sm">
                <p class="text-[0.8125rem] font-semibold mb-xs">World YMCA statements in the constitution</p>
                <x-viz.checklist :items="$labelled(Profile::STATEMENTS, $profile['statements'])" />
            </div>
        </x-card>

        <x-card title="What the movement wants to improve" subtitle="Its own answers to “which areas of improvement would you like your YMCA to work towards?”">
            @forelse ($profile['improvement'] as $code => $text)
                <div class="text-[0.8125rem]">
                    <p class="font-semibold">{{ $categoryNames[$code] ?? $code }}</p>
                    <p class="text-on-surface-variant whitespace-pre-line">{{ \Illuminate\Support\Str::limit($text, 400) }}</p>
                </div>
            @empty
                <p class="text-[0.8125rem] text-on-surface-variant">No areas of improvement were written on the form.</p>
            @endforelse
        </x-card>
    </div>
</section>
