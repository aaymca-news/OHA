{{-- An Administrator's decision, and the history of comments. Expects $artefact. --}}
@can('approve', $artefact)
    @php
        $version = $artefact->kind->value !== 'form' ? $artefact->latestVersion?->versionNumber() : null;
        // An Administrator's own work: approved straight away, without being submitted.
        $own = $artefact->state->value !== 'pending_approval';
        $gaps = $own && $artefact->kind->value === 'form'
            ? (int) $artefact->currentUpload?->findings()->where('severity', 'missing')->whereNull('resolved_at')->count() : 0;
        $visibleTo = 'all AAYMCA staff'.($artefact->kind->value !== 'form' ? ' and the Board Chairperson' : '');
    @endphp
    <x-card :title="($own ? 'Approve your work' : 'Your approval').($version ? ' · version '.$version : '')"
            :subtitle="$own
                ? 'This is your own assessment, so it needs nobody else’s approval. Approving makes it visible to '.$visibleTo.($artefact->kind->value === 'odp' ? ', and sends it to the Board Chairperson to sign.' : ', and moves it on.')
                : 'Submitted by '.$artefact->submitter?->name.'. Approving lets the assessor go on to the next step, and makes it visible to '.$visibleTo.'.'">
        @if ($artefact->gap_ack && ! $own)
            <div class="p-sm rounded bg-serious-wash text-serious-ink text-[0.875rem]">
                Submitted with missing information acknowledged{{ $artefact->gap_ack_reason ? ': “'.$artefact->gap_ack_reason.'”' : '.' }}
            </div>
        @endif
        <div @class(['grid gap-md', 'md:grid-cols-2' => ! $own])>
            <form method="POST" action="{{ route('artefacts.approve', $artefact) }}" class="flex flex-col gap-sm" x-data="{ ack: {{ $gaps > 0 ? 'false' : 'true' }} }">
                @csrf
                {{-- What you are looking at: if it is changed meanwhile, the approval is refused so you can look again. --}}
                <input type="hidden" name="revision" value="{{ \App\Actions\Oha\ApproveArtefact::revision($artefact) }}">
                @if ($gaps > 0)
                    <label class="flex items-start gap-sm text-[0.875rem]">
                        <input type="hidden" name="acknowledge_gaps" value="0">
                        <input type="checkbox" name="acknowledge_gaps" value="1" x-model="ack" class="mt-1">
                        <span>I approve it with {{ $gaps }} open {{ $gaps === 1 ? 'gap' : 'gaps' }}. They stay flagged until resolved.</span>
                    </label>
                @endif
                <label class="flex flex-col gap-xs text-[0.875rem]">
                    <span class="font-semibold">Note (optional)</span>
                    <textarea name="note" rows="3" class="px-sm py-2 rounded border-[1.5px] border-outline-variant">{{ old('note') }}</textarea>
                </label>
                <x-button class="self-start" x-bind:disabled="! ack">Approve</x-button>
            </form>
            @unless ($own)
                <form method="POST" action="{{ route('artefacts.send-back', $artefact) }}" class="flex flex-col gap-sm">
                    @csrf
                    <label class="flex flex-col gap-xs text-[0.875rem]">
                        <span class="font-semibold">Why is it going back? (required)</span>
                        <textarea name="reason" rows="3" required class="px-sm py-2 rounded border-[1.5px] border-outline-variant">{{ old('reason') }}</textarea>
                    </label>
                    <x-button variant="danger" class="self-start">Send back</x-button>
                </form>
            @endunless
        </div>
    </x-card>
@else
    {{-- Why an Administrator cannot approve yet: e.g. the ODP before the OHA form and report are approved. --}}
    @if (auth()->user()->isAdmin() && ($artefact->state->value === 'pending_approval' || (auth()->user()->canAssess($assessment->movement) && in_array($artefact->state->value, ['drafted', 'ready', 'rejected'], true))))
        <x-empty-state icon="lock">{{ Gate::inspect('approve', $artefact)->message() }}</x-empty-state>
    @endif
@endcan

@php($history = $comments($artefact->kind->value))
@if ($history->isNotEmpty() && auth()->user()->isSecretariat())
    <x-card title="Comments and decisions">
        <ul class="flex flex-col gap-sm text-[0.875rem]">
            @foreach ($history as $comment)
                <li class="border-l-4 pl-sm {{ $comment->kind->value === 'send_back' ? 'border-band-atrisk' : 'border-outline-variant' }}">
                    <p>{{ $comment->body }}</p>
                    <p class="text-[0.8125rem] text-on-surface-variant">{{ $comment->author->name }} · {{ ['comment' => 'Comment', 'send_back' => 'Sent back', 'approval_note' => 'Approval note', 'board_note' => 'Board'][$comment->kind->value] }} · {{ $comment->created_at->format('j M Y, H:i') }}</p>
                </li>
            @endforeach
        </ul>
    </x-card>
@endif
