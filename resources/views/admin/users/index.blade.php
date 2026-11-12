{{--
    Users & Roles (Administrators), in the site's newer style (design standard of 10 Nov 2026):
    the groups as choices, then everyone as one row each, or (the demo's showcase) its visitors.
--}}
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
                <tr class="border-t border-[#f2f1ec] hover:bg-[#fafaf8]">
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
                                <span class="block font-semibold">{{ $item }}</span>
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
        {{-- Everyone in the chosen group, one row each (design standard of 10 Nov 2026). --}}
        <x-panel flush>
            @if ($users->isEmpty())
                <div class="px-lg py-lg"><x-empty-state icon="group">No users yet.</x-empty-state></div>
            @else
                <ul class="flex flex-col">
                    @foreach ($users as $u)
                        {{-- One-line @php only in this file: Blade misreads a @php block after a one-line @php(…). --}}
                        @php([$tone, $state] = ! $u->active ? ['neutral', 'Deactivated'] : ($u->isInvitationPending() ? ['info', 'Invitation pending'] : ['good', 'Active']))
                        <li @class(['flex flex-wrap items-center gap-x-md gap-y-sm px-lg py-md border-t border-[#f2f1ec] first:border-0 hover:bg-[#fafaf8]', 'opacity-70' => ! $u->active])>
                            <x-avatar :user="$u" />
                            <div class="flex-[999_1_14rem] min-w-0 flex flex-col gap-[2px]">
                                <a href="{{ route('admin.users.edit', $u) }}" class="text-[0.9375rem] font-semibold hover:underline">{{ $u->name }}</a>
                                <span class="text-[0.8125rem] text-on-surface-variant break-words">{{ $u->email }}{{ $u->title ? ' · '.$u->title : '' }}</span>
                            </div>
                            <div class="flex-[1_1_16rem] min-w-0 flex flex-col gap-[2px] text-[0.8125rem]">
                                <span class="font-semibold">{{ $u->role->label() }}</span>
                                @if ($filter === 'boards')
                                    <span class="text-on-surface-variant">{{ $u->movement?->name }}</span>
                                @elseif ($u->assignedMovements->isEmpty())
                                    <span class="text-on-surface-variant">No assessment assigned</span>
                                @else
                                    <span class="text-on-surface-variant">{{ $u->assignedMovements->sortBy('name')->pluck('name')->join(', ') }}</span>
                                @endif
                            </div>
                            <x-status-pill :tone="$tone" class="min-w-[7rem]">{{ $state }}</x-status-pill>
                            <x-row-action :href="route('admin.users.edit', $u)">Edit<span class="sr-only">: {{ $u->name }}</span></x-row-action>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-panel>
        <x-pagination :paginator="$users" />
    @endif
</x-layouts.app>
