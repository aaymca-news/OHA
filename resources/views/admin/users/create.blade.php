<x-layouts.app title="Invite a user">
    <form method="POST" action="{{ route('admin.users.store') }}" x-data="{ role: '{{ old('role', 'staff') }}' }"
          class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md sm:p-lg flex flex-col gap-md max-w-3xl">
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

        <p x-show="! ['board', 'ngs'].includes(role)" class="text-[0.8125rem] text-on-surface-variant">
            They start with no assessment assigned. Once invited, give them movements to assess on the Assessments page.
        </p>

        {{--
            A movement has one Board Chairperson and one National General Secretary. A new one
            goes to a movement without one: the others are greyed out. Replacing picks among the
            movements that have one, says who is replaced, deactivates them and tells them by email.
        --}}
        @php($holder = fn ($u) => $u ? $u->name.($u->isInvitationPending() ? ' (invitation pending)' : '') : null)
        @php($holders = [
            'board' => $movements->mapWithKeys(fn ($m) => [$m->id => $holder($m->chair)]),
            'ngs' => $movements->mapWithKeys(fn ($m) => [$m->id => $holder($m->generalSecretary)]),
        ])
        @php($labels = ['board' => \App\Enums\Role::Board->label(), 'ngs' => \App\Enums\Role::Ngs->label()])
        <div x-show="['board', 'ngs'].includes(role)" x-cloak class="flex flex-col gap-md"
             x-data="{
                 mode: '{{ old('chair_mode', 'new') }}', movement: '{{ old('board_movement_id') }}', holders: @js($holders), labels: @js($labels),
                 get current() { return this.holders[this.role] ?? {} },
                 get label() { return this.labels[this.role] ?? '' },
                 fits(id) { return (this.mode === 'new') === ((this.current[id] ?? null) === null) },
                 get replacing() { return this.mode === 'replace' ? (this.current[this.movement] ?? null) : null },
             }"
             x-effect="role; if (movement !== '' ? ! fits(movement) : false) movement = ''">
            <fieldset class="flex flex-col gap-xs text-[0.875rem]">
                <legend class="font-semibold mb-xs">This <span x-text="label"></span> is…</legend>
                <label class="flex items-start gap-sm">
                    <input type="radio" name="chair_mode" value="new" x-model="mode" class="mt-1" @checked(old('chair_mode', 'new') === 'new')>
                    <span>For a movement <strong>without</strong> a <span x-text="label"></span></span>
                </label>
                <label class="flex items-start gap-sm">
                    <input type="radio" name="chair_mode" value="replace" x-model="mode" class="mt-1" @checked(old('chair_mode') === 'replace')>
                    <span><strong>Replacing</strong> a movement’s current <span x-text="label"></span></span>
                </label>
            </fieldset>

            <label class="flex flex-col gap-xs text-[0.875rem] sm:max-w-[32rem]">
                <span class="font-semibold">Movement</span>
                <select name="board_movement_id" x-model="movement" class="px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest">
                    <option value="">Choose…</option>
                    @foreach ($movements as $movement)
                        <option value="{{ $movement->id }}" @selected(old('board_movement_id') == $movement->id)
                                :disabled="! fits({{ $movement->id }})"
                                x-text="{{ \Illuminate\Support\Js::from($movement->name) }} + (current[{{ $movement->id }}] ? ' — now: ' + current[{{ $movement->id }}] : '')">
                            {{ $movement->name }}
                        </option>
                    @endforeach
                </select>
                <span class="text-[0.8125rem] text-on-surface-variant"
                      x-text="mode === 'new' ? 'Movements that already have a ' + label + ' are greyed out: choose “Replacing” to change theirs.' : 'Only movements that have a ' + label + ' can be chosen.'">
                    A movement has two users: its Board Chairperson, who signs the ODP, and its National General Secretary, who supplies its evidence.
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
