{{-- The six data-quality dimensions with a verdict each (App\Oha\DqaSummary). --}}
@props(['summary', 'for' => 'form'])
@php($tones = ['pass' => ['good', 'check_circle'], 'warn' => ['warning', 'visibility'], 'gap' => ['serious', 'playlist_remove'], 'fail' => ['critical', 'error']])
<ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-sm">
    @foreach ($summary as $dimension => $result)
        @php([$name, $question] = $for === 'report' ? __('oha.dqa_report.'.$dimension) : __('oha.dqa.'.$dimension))
        <li class="p-sm rounded border-[1.5px] border-outline-variant flex flex-col gap-xs">
            <span class="flex items-center justify-between gap-sm">
                <span class="text-[0.875rem] font-semibold">{{ $name }}</span>
                <x-chip :tone="$tones[$result['verdict']][0]" :icon="$tones[$result['verdict']][1]">{{ __('oha.dqa.verdict.'.$result['verdict']) }}</x-chip>
            </span>
            <span class="text-[0.8125rem] text-on-surface-variant">{{ $question }}</span>
        </li>
    @endforeach
</ul>
