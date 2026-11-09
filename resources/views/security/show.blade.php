<x-layouts.app :title="__('oha.nav.security')">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md items-start">
        {{-- Password --}}
        <x-card title="Change password" padding="lg" class="min-w-0">
            <form method="POST" action="{{ route('user-password.update') }}" class="flex flex-col gap-sm">
                @csrf
                @method('PUT')
                <x-input name="current_password" label="Current password" type="password" autocomplete="current-password" required />
                <x-input name="password" label="New password" type="password" autocomplete="new-password" required
                         hint="At least 12 characters, with upper- and lower-case letters and a number." />
                <x-input name="password_confirmation" label="Repeat the new password" type="password" autocomplete="new-password" required />
                <x-button class="self-start">Change password</x-button>
            </form>
        </x-card>

        {{-- Sessions --}}
        @include('security._devices', ['class' => 'lg:col-span-2'])
    </div>
</x-layouts.app>
