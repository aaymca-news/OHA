@props(['doc'])
{{-- A piece of evidence: a file to download, or a link that opens in a new tab. Both go through the access check. --}}
<a href="{{ route('evidence.download', $doc) }}" @if ($doc->isLink()) target="_blank" rel="noopener noreferrer" @endif
   {{ $attributes->class('inline-flex items-center gap-xs text-primary underline min-w-0 break-words') }}>
    <span class="material-symbols-outlined text-[1.125rem] shrink-0" aria-hidden="true">{{ $doc->isLink() ? 'link' : 'attach_file' }}</span>{{ $doc->title }}
</a>
