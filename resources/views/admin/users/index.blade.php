<x-layouts.app title="Users & Roles">
    <div class="flex flex-wrap items-center gap-sm">
        <nav class="flex gap-xs text-[0.875rem]" aria-label="Which users">
            <a href="{{ route('admin.users.index') }}"
               @class(['px-md py-1.5 rounded border-[1.5px]', 'border-primary bg-primary text-on-primary' => $filter !== 'boards', 'border-outline-variant' => $filter === 'boards'])>AAYMCA Secretariat</a>
            <a href="{{ route('admin.users.index', ['show' => 'boards']) }}"
               @class(['px-md py-1.5 rounded border-[1.5px]', 'border-primary bg-primary text-on-primary' => $filter === 'boards', 'border-outline-variant' => $filter !== 'boards'])>Board Chairpersons</a>
        </nav>
        <a href="{{ route('admin.users.create') }}" class="ml-auto px-md py-2 rounded bg-primary text-on-primary text-[0.875rem] font-semibold">Invite a user</a>
    </div>

    <div class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg overflow-x-auto">
        <table class="w-full min-w-[48rem] text-[0.875rem]">
            <thead class="text-left text-[0.8125rem] uppercase tracking-wider text-on-surface-variant">
                <tr class="border-b-[1.5px] border-outline-variant">
                    <th class="px-md py-sm">Name</th>
                    <th class="px-md py-sm">Role</th>
                    <th class="px-md py-sm">{{ $filter === 'boards' ? 'Movement' : 'Assigned movements' }}</th>
                    <th class="px-md py-sm">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $u)
                    <tr class="border-b border-surface-container last:border-0 {{ $u->active ? '' : 'text-on-surface-variant' }}">
                        <td class="px-md py-sm">
                            <span class="flex items-center gap-sm">
                                <x-avatar :user="$u" />
                                <span class="min-w-0">
                                    <a href="{{ route('admin.users.edit', $u) }}" class="font-semibold text-primary underline">{{ $u->name }}</a>
                                    <span class="block text-[0.8125rem] text-on-surface-variant">{{ $u->email }}{{ $u->title ? ' · '.$u->title : '' }}</span>
                                </span>
                            </span>
                        </td>
                        <td class="px-md py-sm">
                            {{ $u->role->label() }}
                        </td>
                        <td class="px-md py-sm">
                            {{ $filter === 'boards' ? $u->movement?->name : ($u->assignedMovements->pluck('name')->join(', ') ?: '—') }}
                        </td>
                        <td class="px-md py-sm">
                            @if (! $u->active)
                                Deactivated
                            @elseif ($u->isInvitationPending())
                                Invitation pending
                            @else
                                Active
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-md py-md text-on-surface-variant">No users yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
