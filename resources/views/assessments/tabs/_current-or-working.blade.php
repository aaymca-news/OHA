{{--
    The switch between what everyone sees as current (the last approved version) and the
    newer working version, which only those working on it see. Shown only to them, and only
    when there is a working version. Expects $tab, $working (bool: the working one is shown),
    $approvedLabel and $workingLabel.
--}}
<x-segmented label="Which version" class="items-center p-xs rounded-lg bg-surface-container-low">
    <x-segmented.link :href="route('assessments.show', ['assessment' => $assessment, 'tab' => $tab])" :active="! $working" icon="verified">
        Current · {{ $approvedLabel }}
    </x-segmented.link>
    <x-segmented.link :href="route('assessments.show', ['assessment' => $assessment, 'tab' => $tab, 'view' => 'working'])" :active="$working" icon="edit_document">
        Working version · {{ $workingLabel }}
    </x-segmented.link>
    <span class="text-[0.8125rem] text-on-surface-variant flex-1 min-w-[min(15rem,100%)]">
        {{ $working
            ? 'Not approved yet: only the assessors on this movement and the Administrators see it. Make corrections here.'
            : 'The approved version: what all staff'.($tab !== 'form' ? ' and the Board Chairperson' : '').' see.' }}
    </span>
</x-segmented>
