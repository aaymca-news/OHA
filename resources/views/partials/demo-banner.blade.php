{{-- The demo site only: says plainly what this is, and that only made-up data belongs here. --}}
@if (config('oha.demo'))
    <div role="note" class="w-full bg-warning-wash text-warning-ink border-b-[1.5px] border-band-developing px-md py-xs text-center text-[0.8125rem] font-semibold">
        <span class="material-symbols-outlined text-[1rem] align-middle" aria-hidden="true">science</span>
        Demo site, for trying the platform. Use made-up data only: never upload a real movement’s forms, reports or documents.
    </div>
@endif
