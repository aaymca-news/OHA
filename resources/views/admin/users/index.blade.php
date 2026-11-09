<x-layouts.app title="Users & Roles" subtitle="Everyone who signs in: their role, and for AAYMCA staff, the assessments they are assigned. Assessments are handed out on the Assessments page.">
    <div class="flex flex-wrap items-center gap-sm">
        <x-segmented label="Which users">
            <x-segmented.link :href="route('admin.users.index')" :active="$filter !== 'boards'">AAYMCA Secretariat</x-segmented.link>
            <x-segmented.link :href="route('admin.users.index', ['show' => 'boards'])" :active="$filter === 'boards'">National Movements</x-segmented.link>
        </x-segmented>
        <x-button :href="route('admin.users.create')" class="ml-auto">Invite a user</x-button>
    </div>

    <x-table class="min-w-[48rem]">
        <x-slot:head>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>{{ $filter === 'boards' ? 'Movement' : 'Assigned assessments' }}</th>
                <th>Status</th>
            </tr>
        </x-slot:head>
        @forelse ($users as $u)
            <tr class="border-b border-surface-container last:border-0 {{ $u->active ? '' : 'text-on-surface-variant' }}">
                <td>
                    <span class="flex items-center gap-sm">
                        <x-avatar :user="$u" />
                        <span class="min-w-0">
                            <a href="{{ route('admin.users.edit', $u) }}" class="font-semibold text-primary underline">{{ $u->name }}</a>
                            <span class="block text-[0.8125rem] text-on-surface-variant">{{ $u->email }}{{ $u->title ? ' · '.$u->title : '' }}</span>
                        </span>
                    </span>
                </td>
                <td>
                    {{ $u->role->label() }}
                </td>
                <td>
                    @if ($filter === 'boards')
                        {{ $u->movement?->name }}
                    @elseif ($u->assignedMovements->isEmpty())
                        <x-chip tone="neutral" icon="assignment_late">No assessment assigned</x-chip>
                    @else
                        {{ $u->assignedMovements->sortBy('name')->pluck('name')->join(', ') }}
                    @endif
                </td>
                <td>
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
            <tr><td colspan="4"><x-empty-state icon="group">No users yet.</x-empty-state></td></tr>
        @endforelse
    </x-table>
    <x-pagination :paginator="$users" />
</x-layouts.app>
