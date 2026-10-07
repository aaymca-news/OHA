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

        {{--
            A movement has one Board Chairperson. A new one goes to a movement without one: the
            others are greyed out. Replacing picks among the movements that have one, says who
            is replaced, deactivates them and tells them by email.
        --}}
        @php($chairs = $movements->mapWithKeys(fn ($m) => [$m->id => $m->chair ? $m->chair->name.($m->chair->isInvitationPending() ? ' (invitation pending)' : '') : null]))
        <div x-show="role === 'board'" x-cloak class="flex flex-col gap-md"
             x-data="{
                 mode: '{{ old('chair_mode', 'new') }}', movement: '{{ old('board_movement_id') }}', chairs: @js($chairs),
                 fits(id) { return (this.mode === 'new') === (this.chairs[id] === null) },
                 get replacing() { return this.mode === 'replace' ? (this.chairs[this.movement] ?? null) : null },
             }"
             x-effect="if (movement !== '' ? ! fits(movement) : false) movement = ''">
            <fieldset class="flex flex-col gap-xs text-[0.875rem]">
                <legend class="font-semibold mb-xs">This Board Chairperson is…</legend>
                <label class="flex items-start gap-sm">
                    <input type="radio" name="chair_mode" value="new" x-model="mode" class="mt-1" @checked(old('chair_mode', 'new') === 'new')>
                    <span>For a movement <strong>without</strong> a Board Chairperson</span>
                </label>
                <label class="flex items-start gap-sm">
                    <input type="radio" name="chair_mode" value="replace" x-model="mode" class="mt-1" @checked(old('chair_mode') === 'replace')>
                    <span><strong>Replacing</strong> a movement’s current Board Chairperson</span>
                </label>
            </fieldset>

            <label class="flex flex-col gap-xs text-[0.875rem] sm:max-w-[32rem]">
                <span class="font-semibold">Movement</span>
                <select name="board_movement_id" x-model="movement" class="px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest">
                    <option value="">Choose…</option>
                    @foreach ($movements as $movement)
                        <option value="{{ $movement->id }}" @selected(old('board_movement_id') == $movement->id)
                                :disabled="{{ $chairs[$movement->id] !== null ? 'mode === \'new\'' : 'mode === \'replace\'' }}">
                            {{ $movement->name }}{{ $chairs[$movement->id] !== null ? ' — Chairperson: '.$chairs[$movement->id] : '' }}
                        </option>
                    @endforeach
                </select>
                <span class="text-[0.8125rem] text-on-surface-variant"
                      x-text="mode === 'new' ? 'Movements that already have a Board Chairperson are greyed out: choose “Replacing” to change theirs.' : 'Only movements that have a Board Chairperson are listed.'">
                    A movement has one user: its Board Chairperson, who reads the approved report and signs the ODP.
                </span>
            </label>

            <p x-show="replacing" x-cloak
               class="flex items-start gap-xs p-sm rounded bg-serious-wash text-serious-ink text-[0.875rem]">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">swap_horiz</span>
                <span><strong x-text="replacing"></strong> will be replaced: their account is deactivated straight away, and they are told by email that their role has ended. The person you invite gets the invitation to set their password.</span>
            </p>
        </div>

        <x-button class="self-start">Send invitation</x-button>
    </form>
</x-layouts.app>
