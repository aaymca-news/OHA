{{--
    The upload card of the OHA form, report and ODP tabs: a note when the current one is
    approved, the box to drop the file on, optionally the Google Drive link (the ODP's first
    upload) and "What changed", the replace guard, and the button. Pass every value:
      artefact      the form, report or ODP (for the approved note and the replace guard)
      action        where the form posts
      title         the card's title; subtitle its subtitle
      file          the file input's name; accept, fileLabel and prompt as for x-file-drop
      approvedNote  shown when the artefact is approved
      askDriveLink  true to ask for the link to the ODP in Google Drive
      askNote       true to ask what changed in this version
      button        the button's text; buttonIcon its icon (null for none)
--}}
<x-card :title="$title" :subtitle="$subtitle">
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data"
          class="flex flex-col gap-sm">
        @csrf
        @if ($artefact->isApproved())
            <x-callout tone="neutral">
                <p>{{ $approvedNote }}</p>
            </x-callout>
        @endif
        <x-file-drop :name="$file" :accept="$accept" :label="$fileLabel" :prompt="$prompt" />
        @if ($askDriveLink)
            <div x-data="driveLink(@js(old('drive_url', '')))" class="flex flex-col gap-xs">
                {{-- Optional on the demo site, where people try the ODP without a Google Drive document. --}}
                <x-input name="drive_url" :label="config('oha.demo') ? 'Link to the ODP in Google Drive (optional on the demo)' : 'Link to the ODP in Google Drive'" type="url" :required="! config('oha.demo')" x-on:input="link = $event.target.value"
                         placeholder="https://docs.google.com/spreadsheets/d/…"
                         hint="The ODP file itself, not its folder: the Google Sheet (or Doc) the staff write the ODP in. In Google Drive, open it and use Share → Copy link." />
                <p x-show="problem" x-cloak role="alert" class="flex items-start gap-xs text-[0.875rem] text-error">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">error</span><span x-text="problem"></span>
                </p>
            </div>
        @endif
        @if ($askNote)
            <x-textarea name="note" label="What changed in this version? (optional)" rows="2" />
        @endif
        @include('assessments.tabs._replace-guard', ['artefact' => $artefact])
        <x-button class="self-start" :icon="$buttonIcon">{{ $button }}</x-button>
    </form>
</x-card>
