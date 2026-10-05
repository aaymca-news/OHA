{{-- Yes / No / not answered for a set of items, each with an icon and a word as well as a colour. Expects $items = [label => bool|null]. --}}
@props(['items', 'columns' => 'sm:grid-cols-2'])
@php($yes = count(array_filter($items, fn ($v) => $v === true)))
<div class="flex flex-col gap-xs">
    <p class="text-[0.8125rem] text-on-surface-variant"><span class="font-semibold text-on-surface">{{ $yes }} of {{ count($items) }}</span> in place</p>
    <ul class="grid {{ $columns }} gap-x-md gap-y-1 text-[0.8125rem]">
        @foreach ($items as $label => $value)
            <li class="flex items-center gap-xs {{ $value === true ? '' : 'text-on-surface-variant' }}">
                <span @class(['material-symbols-outlined text-[1.125rem]', 'text-good-ink' => $value === true, 'text-critical-ink' => $value === false]) aria-hidden="true">{{ $value === true ? 'check_circle' : ($value === false ? 'cancel' : 'help') }}</span>
                <span>{{ $label }}</span>
                <span class="sr-only">: {{ $value === true ? 'yes' : ($value === false ? 'no' : 'not answered') }}</span>
            </li>
        @endforeach
    </ul>
</div>
