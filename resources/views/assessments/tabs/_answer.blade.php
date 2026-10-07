{{--
    Typing what the form asks for, for one finding: the options of a choice, a figure
    with its unit, the percentages of a group (adding up to 100), the areas of improvement,
    or a sign-off. Expects $finding and $labels (question code => wording on the form).
--}}
@use('App\Oha\Interpreter')
@use('App\Oha\FormDefinition')
@php
    $ref = $finding->ref ?? 'q:'.$finding->question_code;
    [$kind, $key] = explode(':', $ref, 2) + [1 => ''];
    $question = $kind === 'q' ? Interpreter::question($key) : null;
    $group = $kind === 'g' ? ($key === 'income' ? FormDefinition::INCOME : FormDefinition::EXPENSE) : [];
    $id = 'answer-'.$finding->id;
    $currency = Interpreter::currencyCode($finding->formUpload->answers['Q208'] ?? null);
    $input = 'px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest focus:border-primary outline-none';
@endphp

<form method="POST" action="{{ route('findings.answer', $finding) }}" x-show="open" x-cloak
      class="basis-full flex flex-col gap-sm p-sm rounded bg-surface-container-low">
    @csrf
    <input type="hidden" name="finding" value="{{ $finding->id }}">

    @if ($question && $question->type === 'choice')
        <fieldset class="flex flex-col gap-xs text-[0.875rem]">
            <legend class="font-semibold mb-xs">{{ $question->displayCode() }}{{ isset($labels[$key]) ? ' — '.$labels[$key] : '' }}</legend>
            <div class="flex flex-wrap gap-x-md gap-y-xs">
                @foreach ($question->options as $option)
                    <label class="inline-flex items-center gap-xs">
                        <input type="radio" name="value" value="{{ $option }}" required class="accent-primary"> {{ $option }}
                    </label>
                @endforeach
            </div>
        </fieldset>
    @elseif ($question && in_array($question->type, ['number', 'pct', 'money'], true))
        <label class="flex flex-col gap-xs text-[0.875rem]" for="{{ $id }}">
            <span class="font-semibold">{{ $question->displayCode() }}{{ isset($labels[$key]) ? ' — '.$labels[$key] : '' }}</span>
            <span class="flex items-center gap-xs">
                @if ($question->type === 'money' && $currency)
                    <span class="text-on-surface-variant">{{ $currency }}</span>
                @endif
                <input id="{{ $id }}" name="value" required inputmode="decimal" autocomplete="off" class="{{ $input }} w-40">
                @if ($question->type === 'pct')
                    <span class="text-on-surface-variant">%</span>
                @elseif (str_contains(Interpreter::expected($question), 'months'))
                    <span class="text-on-surface-variant">months</span>
                @elseif (str_contains(Interpreter::expected($question), 'years'))
                    <span class="text-on-surface-variant">years</span>
                @endif
            </span>
            <span class="text-[0.8125rem] text-on-surface-variant">
                {{ ucfirst(Interpreter::expected($question)) }}{{ $question->type === 'money' ? ($currency ? ', in '.$currency.' as given at Q208' : '') : '' }}: one figure. Words are understood too, for example “twelve”.
            </span>
        </label>
    @elseif ($question)
        <label class="flex flex-col gap-xs text-[0.875rem]" for="{{ $id }}">
            <span class="font-semibold">{{ $question->displayCode() }}{{ isset($labels[$key]) ? ' — '.$labels[$key] : '' }}</span>
            <textarea id="{{ $id }}" name="value" rows="2" required maxlength="2000" class="{{ $input }}"></textarea>
        </label>
    @elseif ($kind === 'g')
        <fieldset class="flex flex-col gap-xs text-[0.875rem]" x-data="{ shares: {} }">
            <legend class="font-semibold mb-xs">The share of each, in percent. Leave a line blank if it is 0; together they make 100%.</legend>
            @foreach ($group as $code)
                <label class="flex flex-wrap items-center gap-sm">
                    <span class="w-14 font-semibold">{{ $code }}</span>
                    <span class="flex-1 min-w-48">{{ ltrim($labels[$code] ?? '', '- ') ?: $code }}</span>
                    <span class="inline-flex items-center gap-xs">
                        <input name="shares[{{ $code }}]" inputmode="decimal" autocomplete="off" class="{{ $input }} w-24 py-1"
                               x-on:input="shares['{{ $code }}'] = parseFloat($event.target.value) || 0" aria-label="{{ $code }} percentage">
                        <span class="text-on-surface-variant">%</span>
                    </span>
                </label>
            @endforeach
            <p class="text-[0.8125rem]" aria-live="polite">
                Total: <span class="font-semibold" x-text="Object.values(shares).reduce((a, b) => a + b, 0).toLocaleString() + '%'">0%</span>
            </p>
        </fieldset>
    @elseif ($kind === 'c')
        <label class="flex flex-col gap-xs text-[0.875rem]" for="{{ $id }}">
            <span class="font-semibold">Which areas of improvement would the movement like to work towards?</span>
            <textarea id="{{ $id }}" name="value" rows="3" required minlength="10" maxlength="2000" class="{{ $input }}"
                      placeholder="As the movement gave them, for example in an email from the NGS."></textarea>
        </label>
    @elseif ($kind === 's')
        <fieldset class="flex flex-col gap-xs text-[0.875rem]">
            <legend class="font-semibold mb-xs">Did the {{ $key }} take part in completing the form?</legend>
            <div class="flex gap-md">
                <label class="inline-flex items-center gap-xs"><input type="radio" name="value" value="Yes" required class="accent-primary"> Yes</label>
                <label class="inline-flex items-center gap-xs"><input type="radio" name="value" value="No" class="accent-primary"> No</label>
            </div>
        </fieldset>
    @endif

    <p class="text-[0.8125rem] text-on-surface-variant">It is recorded with your name and checked and scored with the form. The file itself is not changed.</p>
    <div class="flex flex-wrap items-center gap-sm">
        <x-button>Save the answer</x-button>
        <button type="button" x-on:click="open = false" class="text-[0.875rem] text-primary underline">Cancel</button>
    </div>
</form>
