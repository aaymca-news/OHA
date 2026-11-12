{{--
    Changing one scored item's answer during an open follow-up, with the reason: one form per
    answer it reads (Q244 reads Q244 and Q245), or, for the income mix, the percentages of
    every source together. Read exactly as the Excel form's answer would be. Expects
    $assessment, $item (from ScoreGaps), $answers (being re-scored), $labels.
--}}
@php
    $control = \App\Support\Ui::control(false, 'sm');
    $shown = fn ($v) => $v === null || $v === '' ? '' : (is_float($v) || is_int($v) ? \App\Support\Viz::figure($v, 6, '') : (string) $v);
@endphp
<div class="flex flex-col gap-sm rounded-xl border border-[#e7e5e0] bg-[#fafaf8] p-md">
    @if ($item['code'] === 'Q214')
        <form method="POST" action="{{ route('baselines.change', $assessment) }}" class="flex flex-col gap-sm">
            @csrf
            <input type="hidden" name="ref" value="g:income">
            <p class="text-[0.8125rem] text-on-surface-variant">The share of each source of income, in %. Leave a line blank if it is 0; together they make 100%.</p>
            <div class="grid gap-sm [grid-template-columns:repeat(auto-fit,minmax(14rem,1fr))]">
                @foreach (\App\Oha\FormDefinition::INCOME as $code)
                    <x-field :label="$code.' · '.\Illuminate\Support\Str::limit(ltrim($labels[$code] ?? '', '- '), 60)" size="sm">
                        <input type="text" inputmode="decimal" name="shares[{{ $code }}]" value="{{ $shown($answers[$code] ?? null) }}" @class($control)>
                    </x-field>
                @endforeach
            </div>
            <x-field label="Why it changed" size="sm">
                <input type="text" name="reason" maxlength="2000" required placeholder="For example: the audited accounts for 2026" @class($control)>
            </x-field>
            <x-button size="sm" class="self-start">Save the percentages</x-button>
        </form>
    @else
        @foreach ($item['drivers'] as $code)
            @php
                $q = \App\Oha\Interpreter::question($code);
            @endphp
            @if ($q !== null)
                <form method="POST" action="{{ route('baselines.change', $assessment) }}" class="flex flex-wrap items-end gap-sm">
                    @csrf
                    <input type="hidden" name="ref" value="q:{{ $code }}">
                    <x-field :label="$q->displayCode().($code !== $item['code'] || count($item['drivers']) > 1 ? ' · '.\Illuminate\Support\Str::limit($labels[$code] ?? '', 50) : '').' · new answer'" size="sm" class="min-w-[min(12rem,100%)]">
                        @if ($q->type === 'choice')
                            <select name="value" required @class($control)>
                                <option value="">Choose</option>
                                @foreach ($q->options ?? [] as $option)
                                    <option value="{{ $option }}" @selected(\App\Oha\Answer::lower($answers[$code] ?? null) === \App\Oha\Answer::lower($option))>{{ $option }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" inputmode="decimal" name="value" required value="{{ $shown($answers[$code] ?? null) }}" placeholder="{{ \App\Oha\Interpreter::expected($q) }}" @class($control)>
                        @endif
                    </x-field>
                    <x-field label="Why it changed" size="sm" class="flex-1 min-w-[min(16rem,100%)]">
                        <input type="text" name="reason" maxlength="2000" required placeholder="For example: the board approved the policy in March" @class($control)>
                    </x-field>
                    <x-button size="sm">Save</x-button>
                </form>
            @endif
        @endforeach
    @endif
</div>
