{{--
    Stage 1 of a movement's assessments as its three documents (decided 27 Nov 2026): 1. the
    Excel assessment form, 2. the report, 3. the ODP, each with when it was approved and a
    download; then when Stage 1 closed and the follow-ups began. The newest assessment open,
    earlier ones folded. Only approved documents: never the checks or what is missing. The
    same for the movement's Board Chairperson and National General Secretary as for AAYMCA
    staff, who also get "Read", to open each one in its assessment.
      entries  from App\Queries\StageOneDocuments, newest first
--}}
@props(['entries'])
<x-panel {{ $attributes }} title="Stage 1 documents"
         subtitle="The Excel assessment form, the report and the ODP as approved, and when, before the follow-ups begin">
    @forelse ($entries as $i => $entry)
        @if ($i === 1)
            <details class="mt-lg border-t border-[#f0eeea] pt-md">
                <summary class="cursor-pointer text-[0.875rem] font-semibold">Earlier assessments ({{ count($entries) - 1 }})</summary>
                <div class="flex flex-col gap-lg mt-md">
        @endif

        <div class="flex flex-col gap-sm">
            <p class="flex flex-wrap items-baseline justify-between gap-x-md gap-y-[2px]">
                <span class="text-[0.9375rem] font-semibold">{{ $entry['assessment']->period_label }} assessment</span>
                <span class="text-[0.8125rem] text-on-surface-variant">
                    {{ $entry['closed_at'] !== null ? 'Stage 1 closed '.$entry['closed_at']->asDay().' · the follow-ups began' : 'Stage 1 under way' }}
                </span>
            </p>
            <ol class="grid gap-sm md:grid-cols-3" aria-label="Stage 1 of the {{ $entry['assessment']->period_label }} assessment">
                @foreach ($entry['steps'] as $n => $step)
                    @php($done = $step['approved_at'] !== null)
                    <li class="flex flex-col gap-sm min-w-0 rounded-xl border border-[#ebe9e4] p-md">
                        <div class="flex items-center gap-sm">
                            @if ($done)
                                <span class="size-5 rounded-full bg-band-strong grid place-items-center flex-none" aria-hidden="true">
                                    <svg class="size-[11px]" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg>
                                </span>
                            @else
                                <span class="size-5 rounded-full box-border border-2 border-[#d3d0c9] bg-surface-container-lowest flex-none" aria-hidden="true"></span>
                            @endif
                            <span class="min-w-0 text-[0.875rem] font-semibold">{{ $n + 1 }}. {{ $step['title'] }}<span class="sr-only">{{ $done ? ' (approved)' : ' (not approved yet)' }}</span></span>
                        </div>
                        @if ($done)
                            <p class="flex flex-col gap-[2px] text-[0.78125rem] text-on-surface-variant">
                                <span class="font-semibold text-on-surface">Approved {{ $step['approved_at']->asDay() }}</span>
                                <span class="break-words">{{ $step['name'] }}</span>
                            </p>
                            @if ($step['key'] === 'odp')
                                <span>
                                    @if ($entry['signature'])
                                        <x-status-pill tone="good">Validated · {{ $entry['signature']->signed_at->asDay() }}</x-status-pill>
                                    @else
                                        <x-status-pill>Not validated</x-status-pill>
                                    @endif
                                </span>
                            @endif
                            <span class="mt-auto flex flex-wrap gap-xs pt-xs">
                                <x-button variant="secondary" size="sm" icon="download" :href="$step['download']"
                                          aria-label="Download the {{ $step['title'] }}, {{ $entry['assessment']->period_label }}">Download</x-button>
                                @if ($step['open'])
                                    <x-button variant="secondary" size="sm" icon="visibility" :href="$step['open']"
                                              aria-label="Read the {{ $step['title'] }} in the {{ $entry['assessment']->period_label }} assessment">Read</x-button>
                                @endif
                            </span>
                        @else
                            <p class="text-[0.78125rem] text-on-surface-variant">Not approved yet</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>

        @if ($i > 0 && $loop->last)
                </div>
            </details>
        @endif
    @empty
        <x-empty-state icon="folder_open">No assessment yet. The approved Excel assessment form, report and ODP appear here once Stage 1 begins.</x-empty-state>
    @endforelse
</x-panel>
