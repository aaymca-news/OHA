<x-layouts.app title="Users & Roles" subtitle="Everyone who signs in: their role, and for AAYMCA staff, the assessments they are assigned. Assessments are handed out on the Assessments page.">
    <div class="flex flex-wrap items-center gap-sm">
        <x-segmented label="Which users">
            <x-segmented.link :href="route('admin.users.index')" :active="$filter !== 'boards' && $filter !== 'visitors'">AAYMCA Secretariat</x-segmented.link>
            <x-segmented.link :href="route('admin.users.index', ['show' => 'boards'])" :active="$filter === 'boards'">National Movements</x-segmented.link>
            @if ($seesVisitors ?? false)
                {{-- The demo's showcase only: who has registered for a demo of their own. --}}
                <x-segmented.link :href="route('admin.users.index', ['show' => 'visitors'])" :active="$filter === 'visitors'">Demo visitors</x-segmented.link>
            @endif
        </x-segmented>
        @unless (config('oha.demo'))
            <x-button :href="route('admin.users.create')" class="ml-auto">Invite a user</x-button>
        @endunless
    </div>

    @if ($filter === 'visitors')
        @php($shared = \App\Demo\Sandboxes::shared())
        <p class="text-[0.875rem] text-on-surface-variant">
            @if ($shared)
                Everyone who has registered for the demo. They all work in this one demo, with you: what they do shows here, on My Work and on the dashboard. An account unused for {{ (int) config('oha.demo_sandbox_days') }} days is deleted (the work stays).
            @else
                Everyone who has registered for a demo of their own. Each works in a copy of this showcase; a demo unused for {{ (int) config('oha.demo_sandbox_days') }} days is deleted, with its account.
            @endif
        </p>
        <x-table class="{{ $shared ? 'min-w-[32rem]' : 'min-w-[52rem]' }}">
            <x-slot:head>
                <tr>
                    <th>Visitor</th>
                    <th>Registered</th>
                    <th>Last active</th>
                    @unless ($shared)
                        <th>Waiting for approval</th>
                        <th>Their demo</th>
                    @endunless
                </tr>
            </x-slot:head>
            @forelse ($visitors as $v)
                <tr class="border-b border-surface-container last:border-0">
                    <td>
                        <span class="font-semibold">{{ $v->name }}</span>
                        <span class="block text-[0.8125rem] text-on-surface-variant">{{ $v->email }}</span>
                    </td>
                    <td>{{ $v->created_at?->asDay() }}</td>
                    <td>
                        <span class="flex flex-wrap items-center gap-sm">
                            {{ $v->updated_at?->asDay() }}
                            {{-- Deleting a visitor's account undoes their work (after a confirmation listing it). --}}
                            <x-button size="sm" variant="secondary" :href="route('demo.showcase.visitors.delete', $v)">Delete…</x-button>
                        </span>
                    </td>
                    @unless ($shared)
                        <td>
                            @php($waiting = $v->sandbox_key !== null ? \App\Demo\DemoApprovals::waiting($v->sandbox_key) : [])
                            @forelse ($waiting as $item)
                                <span class="block font-semibold text-primary">{{ $item }}</span>
                            @empty
                                <span class="text-on-surface-variant">Nothing</span>
                            @endforelse
                        </td>
                        <td>
                            @if ($v->sandbox_key !== null && in_array($v->sandbox_key, $liveDemos, true))
                                <span class="flex flex-wrap items-center gap-sm">
                                    In use
                                    <x-button size="sm" variant="secondary" :href="route('demo.showcase.open', $v)">Open their demo</x-button>
                                </span>
                            @else
                                None now (a new one on signing in)
                            @endif
                        </td>
                    @endunless
                </tr>
            @empty
                <tr><td colspan="5"><x-empty-state icon="group">Nobody has registered for a demo yet.</x-empty-state></td></tr>
            @endforelse
        </x-table>
        <x-pagination :paginator="$visitors" />
    @else
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
    @endif
</x-layouts.app>
