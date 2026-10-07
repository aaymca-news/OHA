<x-layouts.app :title="$target->name">
    <p class="text-[0.875rem] text-on-surface-variant -mt-sm">
        {{ $target->role->label() }}{{ $target->movement ? ' · '.$target->movement->name : '' }}
        · @if (! $target->active) Deactivated @elseif ($target->isInvitationPending()) Invitation pending @else Active @endif
    </p>

    @unless ($canAdminister)
        <p class="p-sm rounded bg-surface-container text-[0.875rem]">{{ Gate::inspect('administer', $target)->message() }}</p>
    @endunless

    <div class="grid lg:grid-cols-2 gap-md items-start">
        {{-- Details --}}
        @if ($canAdminister)
            <form method="POST" action="{{ route('admin.users.update', $target) }}"
                  class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md sm:p-lg flex flex-col gap-md lg:col-span-2">
                @csrf
                @method('PUT')
                <h2 class="text-[1.125rem] font-bold text-primary">Details</h2>
                <div class="grid sm:grid-cols-3 gap-md">
                    <x-input name="name" label="Full name" :value="$target->name" required />
                    <x-input name="email" label="Email" type="email" :value="$target->email" required />
                    <x-input name="title" label="Job title" :value="$target->title" />
                </div>
                @if ($target->isAssessor())
                    @include('admin.users._movements', ['selected' => $target->assignedMovements->pluck('id')->all()])
                @endif
                <x-button class="self-start">Save details</x-button>
            </form>
        @endif

        {{-- Role --}}
        @can('changeRole', $target)
            <form method="POST" action="{{ route('admin.users.role', $target) }}" x-data="{ role: '{{ $target->role->value }}' }"
                  class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md sm:p-lg flex flex-col gap-md">
                @csrf
                @method('PUT')
                <h2 class="text-[1.125rem] font-bold text-primary">Role</h2>
                <select name="role" x-model="role" class="px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest text-[0.875rem]">
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
                @unless (auth()->user()->isSuperAdmin())
                    <p class="text-[0.8125rem] text-on-surface-variant">Only the Super Administrator gives the Administrator roles.</p>
                @endunless
                <div x-show="role === 'board'" x-cloak class="flex flex-col gap-sm">
                    <select name="board_movement_id" class="px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest text-[0.875rem]">
                        <option value="">Board Chairperson of which movement…</option>
                        @foreach ($movements as $movement)
                            <option value="{{ $movement->id }}" @selected($target->movement_id === $movement->id)>{{ $movement->name }}{{ $movement->chair && $movement->chair->id !== $target->id ? ' (now: '.$movement->chair->name.')' : '' }}</option>
                        @endforeach
                    </select>
                    <label class="flex items-start gap-sm text-[0.875rem]">
                        <input type="hidden" name="confirm_replace" value="0">
                        <input type="checkbox" name="confirm_replace" value="1" class="mt-1">
                        <span>If the movement already has a Board Chairperson, replace them (their account is deactivated).</span>
                    </label>
                </div>
                <x-button class="self-start">Change role</x-button>
            </form>
        @else
            <section class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md sm:p-lg">
                <h2 class="text-[1.125rem] font-bold text-primary">Role</h2>
                <p class="text-[0.875rem] text-on-surface-variant mt-xs">{{ Gate::inspect('changeRole', $target)->message() }}</p>
            </section>
        @endcan

        {{-- Account --}}
        @if ($canAdminister)
            <section class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md sm:p-lg flex flex-col gap-md">
                <h2 class="text-[1.125rem] font-bold text-primary">Account</h2>
                <div class="flex flex-wrap gap-sm">
                    @if ($target->isInvitationPending() && $target->active)
                        <form method="POST" action="{{ route('admin.users.invitation', $target) }}">
                            @csrf
                            <x-button variant="secondary">Send a new invitation</x-button>
                        </form>
                    @endif
                    @if ($target->id !== auth()->id())
                        @if ($target->active)
                            <form method="POST" action="{{ route('admin.users.deactivate', $target) }}">
                                @csrf
                                <x-button variant="danger">Deactivate</x-button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.users.reactivate', $target) }}">
                                @csrf
                                <x-button variant="secondary">Reactivate</x-button>
                            </form>
                        @endif
                    @endif
                </div>
                <p class="text-[0.8125rem] text-on-surface-variant">Accounts are never deleted, because the audit trail refers to them. A deactivated user is signed out at once.</p>
            </section>
        @endif
    </div>
</x-layouts.app>
