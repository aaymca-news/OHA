{{--
    The switch between what everyone sees as current (the last approved version) and the
    newer working version, which only those working on it see. Shown only to them, and only
    when there is a working version. Expects $tab, $working (bool: the working one is shown),
    $approvedLabel and $workingLabel.
--}}
<nav class="flex flex-wrap items-center gap-sm p-xs rounded-lg bg-surface-container-low" aria-label="Which version">
    <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => $tab]) }}" @if (! $working) aria-current="page" @endif
       @class(['inline-flex items-center gap-xs px-md py-1.5 rounded text-[0.875rem] font-semibold',
               'bg-primary text-on-primary' => ! $working, 'text-primary hover:bg-surface-container' => $working])>
        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">verified</span> Current · {{ $approvedLabel }}
    </a>
    <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => $tab, 'view' => 'working']) }}" @if ($working) aria-current="page" @endif
       @class(['inline-flex items-center gap-xs px-md py-1.5 rounded text-[0.875rem] font-semibold',
               'bg-primary text-on-primary' => $working, 'text-primary hover:bg-surface-container' => ! $working])>
        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">edit_document</span> Working version · {{ $workingLabel }}
    </a>
    <span class="text-[0.8125rem] text-on-surface-variant flex-1 min-w-[min(15rem,100%)]">
        {{ $working
            ? 'Not approved yet: only the assessors on this movement and the Administrators see it. Make corrections here.'
            : 'The approved version: what all staff'.($tab !== 'form' ? ' and the Board Chairperson' : '').' see.' }}
    </span>
</nav>
