{{--
    What the latest approved OHA form says about the movement beyond the dashboard's figures
    (tidied 23 Nov 2026: what the dashboard already shows, beside the region, is not repeated
    here: staff, volunteers, the Board's size and make-up, the policies, the income mix, the
    margin, the reserve, the international and restricted shares). Expects $profile, $latest.
--}}
@use('App\Oha\Profile')
@use('App\Support\Viz')
@php
    $f = $profile['finance'];
    $b = $profile['board'];
    $labelled = fn (array $labels, ?array $values) => $values === null ? [] : collect($labels)->mapWithKeys(fn ($label, $code) => [$label => $values[$code] ?? null])->all();
    $categoryNames = \App\Models\Category::names();
@endphp

<section class="flex flex-col gap-md" aria-labelledby="glance">
    <div>
        <h2 id="glance" class="text-[1.125rem] font-bold text-on-surface">At a glance</h2>
        <p class="text-[0.8125rem] text-on-surface-variant">More from the approved {{ $latest->period_label }} OHA form. Money is in the movement’s own currency{{ $f['currency'] ? ' ('.$f['currency'].')' : '' }}.</p>
    </div>

    <div class="grid lg:grid-cols-2 gap-md items-start">
        <x-panel stack title="Accounts" subtitle="Last financial year (Q209–Q248).">
            <dl class="grid grid-cols-3 gap-md">
                @foreach ([['Income', $f['income']], ['Expenditure', $f['expenditure']], [$f['balance'] !== null && $f['balance'] < 0 ? 'Deficit' : 'Surplus', $f['balance']]] as [$label, $value])
                    <div>
                        <dt class="text-[0.8125rem] text-on-surface-variant">{{ $label }}</dt>
                        <dd class="text-[1.25rem] font-bold text-on-surface leading-tight">{{ Viz::compact($value) }}</dd>
                    </div>
                @endforeach
            </dl>
            <dl class="grid grid-cols-2 gap-x-md gap-y-xs text-[0.8125rem]">
                <dt class="text-on-surface-variant">Liabilities ÷ assets</dt><dd class="font-semibold">{{ $f['liability_ratio'] !== null ? Viz::number($f['liability_ratio'], 2).'×' : '—' }}{{ $f['liability_ratio'] !== null && $f['liability_ratio'] > 1 ? ' · more owed than owned' : '' }}</dd>
                <dt class="text-on-surface-variant">Outstanding debt</dt><dd class="font-semibold">{{ $f['has_debt'] === true ? Viz::compact($f['debt']) : ($f['has_debt'] === false ? 'None' : '—') }}</dd>
                <dt class="text-on-surface-variant">Can cover 2 years of costs</dt><dd class="font-semibold">{{ $f['covers_two_years'] === null ? '—' : ($f['covers_two_years'] ? 'Yes' : 'No') }}</dd>
                <dt class="text-on-surface-variant">Fundraising cost</dt><dd class="font-semibold">{{ $f['fundraising_pct'] !== null ? Viz::number($f['fundraising_pct'], 1).'% of income' : '—' }}</dd>
            </dl>
            @if ($profile['expense_mix'])
                <x-viz.stacked caption="Where the money goes" :segments="collect(Profile::EXPENSE_GROUPS)->map(fn ($g, $k) => ['label' => $g['label'], 'value' => $profile['expense_mix'][$k]])->values()->all()" />
            @else
                <p class="text-[0.8125rem] text-on-surface-variant">Expenditure by type not given.</p>
            @endif
        </x-panel>

        <x-panel stack title="Board and governance" subtitle="Current members (Q315–Q319) and how the Board works.">
            @if ($b['size'])
                <x-viz.stacked :segments="array_values(array_filter([['label' => 'Women', 'value' => $b['women'] ?? 0], ['label' => 'Men', 'value' => $b['men'] ?? 0], ($b['non_binary'] ?? 0) > 0 ? ['label' => 'Non-binary', 'value' => $b['non_binary']] : null]))" />
                <dl class="grid grid-cols-2 gap-x-md gap-y-xs text-[0.8125rem]">
                    <dt class="text-on-surface-variant">Size the constitution allows</dt><dd class="font-semibold">{{ $b['min'] !== null && $b['max'] !== null ? Viz::number($b['min']).'–'.Viz::number($b['max']) : '—' }}</dd>
                    <dt class="text-on-surface-variant">First term</dt><dd class="font-semibold">{{ Viz::number($b['first_term']) }}</dd>
                    <dt class="text-on-surface-variant">Term length</dt><dd class="font-semibold">{{ $b['term_years'] !== null ? Viz::number($b['term_years']).' years' : '—' }}</dd>
                    <dt class="text-on-surface-variant">Meetings last year</dt><dd class="font-semibold">{{ $b['meetings'] ?? '—' }}</dd>
                </dl>
            @else
                <p class="text-[0.8125rem] text-on-surface-variant">Board make-up not given.</p>
            @endif
            <x-viz.checklist :items="$labelled(Profile::GOVERNANCE_PRACTICES, $profile['governance'])" columns="grid-cols-1 sm:grid-cols-2" />
        </x-panel>

        <x-panel stack title="Strategy and Vision 2030" subtitle="Strategic Plan (Q610–Q635).">
            <x-viz.checklist :items="$labelled(Profile::PRACTICES, $profile['practices'])" />
            @if ($profile['vision_2030'] !== null)
                <div class="border-t border-[#f0eeea] pt-sm">
                    <p class="text-[0.8125rem] font-semibold mb-xs">Vision 2030 components in the plan</p>
                    <x-viz.checklist :items="$labelled(Profile::VISION_2030, $profile['vision_2030'])" />
                </div>
            @endif
            <div class="border-t border-[#f0eeea] pt-sm">
                <p class="text-[0.8125rem] font-semibold mb-xs">World YMCA statements in the constitution</p>
                <x-viz.checklist :items="$labelled(Profile::STATEMENTS, $profile['statements'])" />
            </div>
        </x-panel>

        <x-panel stack title="What the movement wants to improve" subtitle="Its own answers to “which areas of improvement would you like your YMCA to work towards?”">
            @forelse ($profile['improvement'] as $code => $text)
                <div class="text-[0.8125rem]">
                    <p class="font-semibold">{{ $categoryNames[$code] ?? $code }}</p>
                    <p class="text-on-surface-variant whitespace-pre-line">{{ \Illuminate\Support\Str::limit($text, 400) }}</p>
                </div>
            @empty
                <x-empty-state icon="edit_note">No areas of improvement were written on the form.</x-empty-state>
            @endforelse
        </x-panel>
    </div>
</section>
