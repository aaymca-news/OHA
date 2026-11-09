{{-- Adding a National Movement ($movement null), or correcting one's details. --}}
<x-layouts.app :title="$movement ? 'Edit '.$movement->name : 'Add a National Movement'"
               :subtitle="$movement ? 'Its web address stays '.route('movements.show', $movement).'.' : 'When a movement joins the Alliance. It starts with no rating until one of its OHA forms is approved.'">
    @php($select = 'px-sm py-2 rounded border-[1.5px] bg-surface-container-lowest focus:border-primary outline-none')
    <form method="POST" action="{{ $movement ? route('admin.movements.update', $movement) : route('admin.movements.store') }}"
          class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md sm:p-lg flex flex-col gap-md max-w-3xl">
        @csrf
        @if ($movement)
            @method('PUT')
        @endif

        <div class="grid sm:grid-cols-2 gap-md">
            <x-input name="name" label="Name" :value="$movement?->name" required maxlength="80" hint="As the Alliance writes it, for example: Botswana YMCA" />
            <x-input name="country" label="Country" :value="$movement?->country" required maxlength="60"
                     hint="The country’s usual short name, for example: Botswana. Uploaded OHA forms are checked against it (Q102)." />
            <x-input name="city" label="City" :value="$movement?->city" required maxlength="60" hint="Where the national office is." />
            @foreach ([['zone_id', 'Zone', $zones, 'name', $movement?->zone_id], ['membership_status_id', 'Membership', $memberships, 'label', $movement?->membership_status_id]] as [$field, $label, $options, $text, $current])
                <label class="flex flex-col gap-xs text-[0.875rem]">
                    <span class="font-semibold">{{ $label }}</span>
                    <select name="{{ $field }}" required @class([$select, 'border-error' => $errors->has($field), 'border-outline-variant' => ! $errors->has($field)])>
                        <option value="" disabled @selected(old($field, $current) === null)>Choose…</option>
                        @foreach ($options as $option)
                            <option value="{{ $option->id }}" @selected((int) old($field, $current) === $option->id)>{{ $option->{$text} }}</option>
                        @endforeach
                    </select>
                    @error($field)
                        <span class="text-[0.8125rem] text-error">{{ $message }}</span>
                    @enderror
                </label>
            @endforeach
            <x-input name="planned_on" label="Planned first assessment (optional)" type="month" :value="$movement?->planned_assessment_on?->format('Y-m')"
                     hint="Shown as its next assessment until it is first rated." />
        </div>

        <div class="flex flex-wrap items-center gap-sm">
            <x-button>{{ $movement ? 'Save the details' : 'Add the movement' }}</x-button>
            <a href="{{ $movement ? route('movements.show', $movement) : route('movements.index') }}" class="text-[0.875rem] text-primary underline">Cancel</a>
        </div>
    </form>
</x-layouts.app>
