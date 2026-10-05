{{-- Movement checkboxes for an AAYMCA assessor. Expects $movements and $selected (list of ids). --}}
<fieldset class="flex flex-col gap-xs">
    <legend class="text-[0.875rem] font-semibold mb-xs">Movements this person assesses</legend>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-md gap-y-xs text-[0.875rem]">
        @foreach ($movements as $movement)
            <label class="flex items-center gap-sm">
                <input type="checkbox" name="movement_ids[]" value="{{ $movement->id }}" @checked(in_array($movement->id, old('movement_ids', $selected)))>
                {{ $movement->name }} <span class="text-[0.8125rem] text-on-surface-variant">{{ $movement->zone->name }}</span>
            </label>
        @endforeach
    </div>
</fieldset>
