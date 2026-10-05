<x-layouts.app :title="__('oha.nav.notifications')" subtitle="Work routed to you, and news on work you are waiting for. Each one is also sent by email.">
    @if ($notifications->whereNull('read_at')->isNotEmpty())
        <x-slot:actions>
            <form method="POST" action="{{ route('notifications.read') }}">
                @csrf
                <x-button variant="secondary">Mark all as read</x-button>
            </form>
        </x-slot:actions>
    @endif

    @forelse ($notifications as $n)
        @php($tone = ['action' => 'warning', 'good' => 'good', 'serious' => 'serious', 'info' => 'info'][$n->data['tone'] ?? 'info'] ?? 'info')
        <a href="{{ $n->data['link'] ?? route('dashboard') }}"
           class="flex items-start gap-sm p-sm rounded-lg border-[1.5px] {{ $n->read_at ? 'border-surface-container bg-surface-container-low' : 'border-outline-variant bg-surface-container-lowest' }} hover:border-primary">
            <x-chip :tone="$tone" :icon="['warning' => 'pending_actions', 'good' => 'check_circle', 'serious' => 'report', 'info' => 'info'][$tone]">{{ $n->read_at ? 'Read' : 'New' }}</x-chip>
            <span class="flex-1">
                <span class="block text-[0.875rem] font-semibold text-primary">{{ $n->data['subject'] ?? '' }}</span>
                <span class="block text-[0.875rem]">{{ $n->data['body'] ?? '' }}</span>
                <span class="block text-[0.8125rem] text-on-surface-variant">{{ $n->created_at->diffForHumans() }}</span>
            </span>
        </a>
    @empty
        <x-empty-state icon="notifications_off">Nothing yet. Notifications appear here when work is routed to you.</x-empty-state>
    @endforelse
</x-layouts.app>
