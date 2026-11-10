{{-- The demo's showcase only: the Super Administrator confirms deleting a visitor's account and undoing their work. --}}
<x-layouts.app :title="'Delete '.$visitor->name.'’s account'" subtitle="Their account goes, and everything they did in the demo is undone, newest first.">
    <x-card padding="lg">
        <div class="flex flex-col gap-md text-[0.9375rem]">
            <p><strong>{{ $visitor->name }}</strong> · {{ $visitor->email }} · registered {{ $visitor->created_at?->asDay() }}</p>

            @if ($plan['ids'] === [])
                <p>They have done nothing in the demo that can be undone: only their account will go.</p>
            @else
                <p>{{ $plan['own'] }} {{ $plan['own'] === 1 ? 'change' : 'changes' }} of theirs will be undone.</p>
                @if ($plan['actions'] !== [])
                    <div>
                        <p class="font-semibold">What is undone</p>
                        <ul class="list-disc pl-lg">
                            @foreach (array_unique($plan['actions']) as $action)
                                <li>{{ $action }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($plan['others'] !== [])
                    <x-callout tone="warning">
                        Work by others built on theirs is undone too:
                        @foreach ($plan['others'] as $who => $count)
                            {{ $count }} {{ $count === 1 ? 'change' : 'changes' }} by {{ $who }}@if (! $loop->last), @endif
                        @endforeach.
                    </x-callout>
                @endif
            @endif

            <form method="POST" action="{{ route('demo.showcase.visitors.destroy', $visitor) }}" class="flex flex-wrap gap-sm"
                  x-data x-on:submit="if (! confirm('Delete this account and undo the work listed? This cannot be undone.')) $event.preventDefault()">
                @csrf
                @method('DELETE')
                <x-button>Delete the account and undo their work</x-button>
                <x-button variant="secondary" :href="route('admin.users.index', ['show' => 'visitors'])">Cancel</x-button>
            </form>
        </div>
    </x-card>
</x-layouts.app>
