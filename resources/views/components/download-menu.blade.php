{{--
    The dashboard's Download (decided 23 Nov 2026): one quiet button opening a small menu of
    two formats, a PDF to read and share, or an Excel workbook to work the figures further.
      pdf   the PDF's address
      xlsx  the workbook's address
--}}
@props(['pdf', 'xlsx'])
<div {{ $attributes->class('relative') }} x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <x-row-action x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-controls="download-formats">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
        Download
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
    </x-row-action>
    <ul id="download-formats" x-show="open" x-cloak x-transition.opacity
        class="absolute right-0 z-30 mt-xs w-[15.5rem] flex flex-col rounded-xl border border-[#e7e5e0] bg-surface-container-lowest p-[6px] shadow-[0_8px_24px_rgba(20,20,20,0.12)]">
        <li>
            <a href="{{ $pdf }}" x-on:click="open = false" class="flex flex-col rounded-lg px-[10px] py-[8px] text-on-surface no-underline hover:bg-[#f4f2ee]">
                <span class="text-[0.875rem] font-semibold">PDF</span>
                <span class="text-[0.78125rem] text-on-surface-variant">To read, print and share</span>
            </a>
        </li>
        <li>
            <a href="{{ $xlsx }}" x-on:click="open = false" class="flex flex-col rounded-lg px-[10px] py-[8px] text-on-surface no-underline hover:bg-[#f4f2ee]">
                <span class="text-[0.875rem] font-semibold">Excel workbook</span>
                <span class="text-[0.78125rem] text-on-surface-variant">To sort, filter and work the figures</span>
            </a>
        </li>
    </ul>
</div>
