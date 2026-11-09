<x-layouts.app :title="$target->name">
    <p class="text-[0.875rem] text-on-surface-variant -mt-sm">
        {{ $target->role->label() }}{{ $target->movement ? ' · '.$target->movement->name : '' }}
        · @if (! $target->active) Deactivated @elseif ($target->isInvitationPending()) Invitation pending @else Active @endif
    </p>

    @unless ($canAdminister)
        <x-callout tone="neutral">{{ Gate::inspect('administer', $target)->message() }}</x-callout>
    @endunless

    <div class="grid lg:grid-cols-2 gap-md items-start">
        {{-- Details --}}
        @if ($canAdminister)
            <x-card title="Details" padding="lg" class="lg:col-span-2">
                <form method="POST" action="{{ route('admin.users.update', $target) }}" class="flex flex-col gap-md">
                    @csrf
                    @method('PUT')
                    <div class="grid sm:grid-cols-3 gap-md">
                        <x-input name="name" label="Full name" :value="$target->name" required />
                        <x-input name="email" label="Email" type="email" :value="$target->email" required />
                        <x-input name="title" label="Job title" :value="$target->title" />
                    </div>
                    <x-button class="self-start">Save details</x-button>
                    @if ($target->isAssessor())
                        <div class="border-t border-surface-container pt-md">
                            @include('admin.users._assignments')
                        </div>
                    @endif
                </form>
            </x-card>
        @endif

        {{-- Role --}}
        @can('changeRole', $target)
            <x-card title="Role" padding="lg">
                <form method="POST" action="{{ route('admin.users.role', $target) }}" x-data="{ role: '{{ $target->role->value }}' }" class="flex flex-col gap-md">
                    @csrf
                    @method('PUT')
                    <x-select name="role" x-model="role" aria-label="Role"
                              :hint="auth()->user()->isSuperAdmin() ? null : 'Only the Super Administrator gives the Administrator roles.'">
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}">{{ $role->label() }}</option>
                        @endforeach
                    </x-select>
                    @foreach (['board' => 'chair', 'ngs' => 'generalSecretary'] as $value => $relation)
                        @php($roleLabel = \App\Enums\Role::from($value)->label())
                        <div x-show="role === '{{ $value }}'" x-cloak class="flex flex-col gap-sm">
                            <x-select name="board_movement_id" x-bind:disabled="role !== '{{ $value }}'" :placeholder="$roleLabel.' of which movement…'" :aria-label="$roleLabel.' of which movement'">
                                @foreach ($movements as $movement)
                                    @php($now = $movement->{$relation})
                                    <option value="{{ $movement->id }}" @selected($target->movement_id === $movement->id)>{{ $movement->name }}{{ $now && $now->id !== $target->id ? ' (now: '.$now->name.')' : '' }}</option>
                                @endforeach
                            </x-select>
                            <label class="flex items-start gap-sm text-[0.875rem]">
                                <input type="hidden" name="confirm_replace" value="0" :disabled="role !== '{{ $value }}'">
                                <input type="checkbox" name="confirm_replace" value="1" class="mt-1" :disabled="role !== '{{ $value }}'">
                                <span>If the movement already has a {{ $roleLabel }}, replace them (their account is deactivated).</span>
                            </label>
                        </div>
                    @endforeach
                    <x-button class="self-start">Change role</x-button>
                </form>
            </x-card>
        @else
            <x-card title="Role" padding="lg">
                <p class="text-[0.875rem] text-on-surface-variant">{{ Gate::inspect('changeRole', $target)->message() }}</p>
            </x-card>
        @endcan

        {{-- Account --}}
        @if ($canAdminister)
            <x-card title="Account" padding="lg">
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
            </x-card>
        @endif
    </div>
</x-layouts.app>
