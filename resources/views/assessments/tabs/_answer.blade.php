{{--
    Typing what settles a finding of the OHA form check (see App\Oha\FindingFields): one or
    more answers, the percentages of a group (adding up to 100), the areas of improvement,
    or a sign-off. Beside each box: the kind of answer it takes, with an example, and what
    the form holds now. Expects $finding and $labels (question code => wording on the form).
--}}
@use('App\Oha\Interpreter')
@use('App\Oha\FindingFields')
@php
    $fields = FindingFields::for($finding);
    $upload = $finding->formUpload;
    $currency = Interpreter::currencyCode($upload->answers['Q208'] ?? null);
    $now = $upload->answers;
    $input = 'px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest focus:border-primary outline-none';
    $several = $fields['kind'] === 'answers' && count($fields['codes']) > 1;
    $shown = fn ($v) => $v === null || $v === '' ? 'blank' : (is_bool($v) ? ($v ? 'TRUE' : 'FALSE') : '“'.\Illuminate\Support\Str::limit((string) $v, 60).'”');
@endphp

<form method="POST" action="{{ route('findings.answer', $finding) }}" x-show="open" x-cloak
      class="basis-full flex flex-col gap-md p-sm rounded bg-surface-container-low">
    @csrf
    <input type="hidden" name="finding" value="{{ $finding->id }}">

    @if ($fields['kind'] === 'answers')
        @if ($several)
            <p class="text-[0.8125rem] text-on-surface-variant">Correct whichever of these is wrong; leave a box empty to keep what the form has.</p>
        @endif
        @foreach ($fields['codes'] as $code)
            @php
                $q = Interpreter::question($code);
                $guide = FindingFields::guide($q, $currency);
                $id = 'answer-'.$finding->id.'-'.\Illuminate\Support\Str::slug($code);
                $name = $several ? 'answers['.$code.']' : 'value';
                $label = $q->displayCode().(isset($labels[$code]) ? ' — '.ltrim($labels[$code], '- ') : '');
            @endphp
            <div class="flex flex-col gap-xs text-[0.875rem]">
                @if ($q->type === 'choice')
                    <fieldset class="flex flex-col gap-xs">
                        <legend class="font-semibold mb-xs">{{ $label }}</legend>
                        <div class="flex flex-wrap gap-x-md gap-y-xs">
                            @foreach ($q->options as $option)
                                <label class="inline-flex items-center gap-xs min-h-6">
                                    <input type="radio" name="{{ $name }}" value="{{ $option }}" @if (! $several) required @endif class="accent-primary"> {{ $option }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @elseif (in_array($q->type, ['number', 'pct', 'money'], true))
                    <label for="{{ $id }}" class="font-semibold">{{ $label }}</label>
                    <span class="flex items-center gap-xs">
                        @if ($q->type === 'money' && $currency)
                            <span class="text-on-surface-variant">{{ $currency }}</span>
                        @endif
                        <input id="{{ $id }}" name="{{ $name }}" @if (! $several) required @endif inputmode="decimal" autocomplete="off" class="{{ $input }} w-40 max-w-full">
                        @if ($q->type === 'pct')
                            <span class="text-on-surface-variant">%</span>
                        @elseif (str_contains($guide['type'], 'months'))
                            <span class="text-on-surface-variant">months</span>
                        @elseif (str_contains($guide['type'], 'years'))
                            <span class="text-on-surface-variant">years</span>
                        @endif
                    </span>
                @else
                    <label for="{{ $id }}" class="font-semibold">{{ $label }}</label>
                    <textarea id="{{ $id }}" name="{{ $name }}" rows="2" @if (! $several) required @endif maxlength="2000" class="{{ $input }}"></textarea>
                @endif
                <p class="flex flex-wrap items-baseline gap-x-sm gap-y-0.5 text-[0.8125rem]">
                    <span class="inline-flex items-center gap-xs font-semibold text-primary">
                        <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">info</span>Answer: {{ $guide['type'] }}
                    </span>
                    <span class="text-on-surface-variant">{{ $guide['example'] }}</span>
                    <span class="text-on-surface-variant">The form has {{ $shown($now[$code] ?? null) }}.</span>
                </p>
            </div>
        @endforeach
    @elseif ($fields['kind'] === 'shares')
        <fieldset class="flex flex-col gap-xs text-[0.875rem]" x-data="{ shares: {} }">
            <legend class="font-semibold mb-xs">The share of each, in percent</legend>
            <p class="text-[0.8125rem] flex flex-wrap gap-x-sm">
                <span class="inline-flex items-center gap-xs font-semibold text-primary"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">info</span>Answer: percentages, 0 to 100</span>
                <span class="text-on-surface-variant">Leave a line blank if it is 0. Together they must make 100%.</span>
            </p>
            @foreach ($fields['codes'] as $code)
                <label class="flex flex-wrap items-center gap-sm">
                    <span class="w-14 font-semibold">{{ $code }}</span>
                    <span class="flex-1 min-w-[min(12rem,100%)]">{{ ltrim($labels[$code] ?? '', '- ') ?: $code }}</span>
                    <span class="inline-flex items-center gap-xs">
                        <input name="shares[{{ $code }}]" inputmode="decimal" autocomplete="off" class="{{ $input }} w-24 py-1"
                               value="{{ is_numeric($now[$code] ?? null) ? $now[$code] : '' }}"
                               x-init="shares['{{ $code }}'] = parseFloat($el.value) || 0" x-on:input="shares['{{ $code }}'] = parseFloat($event.target.value) || 0" aria-label="{{ $code }} percentage">
                        <span class="text-on-surface-variant">%</span>
                    </span>
                </label>
            @endforeach
            <p class="text-[0.8125rem]" aria-live="polite">
                Total: <span class="font-semibold" x-text="Object.values(shares).reduce((a, b) => a + b, 0).toLocaleString() + '%'">0%</span>
            </p>
        </fieldset>
    @elseif ($fields['kind'] === 'comment')
        <label class="flex flex-col gap-xs text-[0.875rem]" for="answer-{{ $finding->id }}">
            <span class="font-semibold">Which areas of improvement would the movement like to work towards?</span>
            <textarea id="answer-{{ $finding->id }}" name="value" rows="3" required minlength="10" maxlength="2000" class="{{ $input }}"
                      placeholder="As the movement gave them, for example in an email from the NGS."></textarea>
            <span class="text-[0.8125rem]"><span class="font-semibold text-primary">Answer: text,</span> <span class="text-on-surface-variant">a sentence or more. It feeds the report and the ODP.</span></span>
        </label>
    @elseif ($fields['kind'] === 'signoff')
        <fieldset class="flex flex-col gap-xs text-[0.875rem]">
            <legend class="font-semibold mb-xs">Did the {{ $fields['key'] }} take part in completing the form?</legend>
            <div class="flex gap-md">
                <label class="inline-flex items-center gap-xs"><input type="radio" name="value" value="Yes" required class="accent-primary"> Yes</label>
                <label class="inline-flex items-center gap-xs"><input type="radio" name="value" value="No" class="accent-primary"> No</label>
            </div>
            <span class="text-[0.8125rem]"><span class="font-semibold text-primary">Answer: Yes or No.</span></span>
        </fieldset>
    @endif

    <p class="text-[0.8125rem] text-on-surface-variant">Saved with your name, checked and scored with the form, and written into a corrected copy of the workbook. The file as uploaded is kept unchanged.</p>
    <div class="flex flex-wrap items-center gap-sm">
        <x-button>Save the answer</x-button>
        <button type="button" x-on:click="open = false" class="text-[0.875rem] text-primary underline">Cancel</button>
    </div>
</form>
