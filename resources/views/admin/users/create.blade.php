<x-layouts.app title="Invite a user">
    <form method="POST" action="{{ route('admin.users.store') }}" x-data="{ role: '{{ old('role', 'staff') }}' }"
          class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-lg flex flex-col gap-md max-w-3xl">
        @csrf
        <p class="text-[0.875rem] text-on-surface-variant">They will get an email with a link to set their password. The link works once and expires in 7 days.</p>

        <div class="grid sm:grid-cols-2 gap-md">
            <x-input name="name" label="Full name" required />
            <x-input name="email" label="Email" type="email" required />
            <x-input name="title" label="Job title" hint="For example: Zonal Coordinator, East Africa" />
            <label class="flex flex-col gap-xs text-[0.875rem]">
                <span class="font-semibold">Role</span>
                <select name="role" x-model="role" class="px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest">
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
                @unless (auth()->user()->isSuperAdmin())
                    <span class="text-[0.8125rem] text-on-surface-variant">Only the Super Administrator gives the Administrator roles.</span>
                @endunless
            </label>
        </div>

        <div x-show="role !== 'board'">
            @include('admin.users._movements', ['selected' => []])
        </div>

        <div x-show="role === 'board'" x-cloak class="flex flex-col gap-md">
            <label class="flex flex-col gap-xs text-[0.875rem] sm:max-w-[28rem]">
                <span class="font-semibold">Board Chairperson of which movement</span>
                <select name="board_movement_id" class="px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest">
                    <option value="">Choose…</option>
                    @foreach ($movements as $movement)
                        <option value="{{ $movement->id }}" @selected(old('board_movement_id') == $movement->id)>{{ $movement->name }}{{ $movement->chair ? ' (now: '.$movement->chair->name.')' : '' }}</option>
                    @endforeach
                </select>
                <span class="text-[0.8125rem] text-on-surface-variant">A movement has one user: its Board Chairperson, who reads the approved report and signs the ODP.</span>
            </label>
            <label class="flex items-start gap-sm text-[0.875rem]">
                <input type="hidden" name="confirm_replace" value="0">
                <input type="checkbox" name="confirm_replace" value="1" class="mt-1" @checked(old('confirm_replace'))>
                <span>If the movement already has a Board Chairperson, replace them (their account is deactivated).</span>
            </label>
        </div>

        <x-button class="self-start">Send invitation</x-button>
    </form>
</x-layouts.app>
