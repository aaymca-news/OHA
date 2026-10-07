<x-layouts.app :title="__('oha.nav.profile')" subtitle="Your photo and details. Your password and sign-ins are under Security.">
    <div class="grid lg:grid-cols-2 gap-md items-start">
        {{-- Profile photo: shown wherever the person's name appears. --}}
        <section class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-lg flex flex-col gap-md">
            <div>
                <h2 class="text-[1.125rem] font-bold text-primary">Profile photo</h2>
                <p class="text-[0.875rem] text-on-surface-variant">Shown beside your name across the platform: the top bar, Users &amp; Roles, and the movements you assess or represent.</p>
            </div>
            <div class="flex flex-wrap items-center gap-md">
                <x-avatar :user="$user" size="lg" />
                <div class="flex flex-col gap-xs text-[0.875rem]">
                    <span class="font-semibold">{{ $user->name }}</span>
                    <span class="text-on-surface-variant">{{ $user->avatar_path ? 'Your photo' : 'No photo yet: your initials show instead.' }}</span>
                </div>
            </div>
            <form method="POST" action="{{ route('profile.photo') }}" enctype="multipart/form-data" class="flex flex-col gap-sm">
                @csrf
                <label class="flex flex-col gap-xs text-[0.875rem]">
                    <span class="font-semibold">{{ $user->avatar_path ? 'Replace the photo' : 'Add a photo' }}</span>
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required
                           class="text-[0.875rem] file:mr-sm file:px-md file:py-1.5 file:rounded file:border-[1.5px] file:border-outline-variant file:bg-surface-container-lowest file:text-primary file:font-semibold hover:file:border-primary">
                    <span class="text-[0.8125rem] text-on-surface-variant">A JPG or PNG, up to 5 MB. It is cropped to a square around the centre.</span>
                </label>
                <x-button class="self-start">Save photo</x-button>
            </form>
            @if ($user->avatar_path)
                <form method="POST" action="{{ route('profile.photo.remove') }}">
                    @csrf
                    @method('DELETE')
                    <button class="text-[0.875rem] text-primary underline">Remove the photo</button>
                </form>
            @endif
        </section>

        {{-- Details: the name and title are theirs to correct; the rest is set by the Administrators. --}}
        <section class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-lg flex flex-col gap-md">
            <h2 class="text-[1.125rem] font-bold text-primary">Your details</h2>
            <form method="POST" action="{{ route('profile.update') }}" class="flex flex-col gap-sm">
                @csrf
                @method('PUT')
                <x-input name="name" label="Full name" :value="$user->name" required autocomplete="name" />
                <x-input name="title" label="Job title" :value="$user->title" autocomplete="organization-title"
                         :hint="$user->isSecretariat() ? 'For example: Zonal Coordinator, East Africa' : 'For example: Board Chairperson'" />
                <x-button class="self-start">Save details</x-button>
            </form>

            <dl class="grid grid-cols-[auto_1fr] gap-x-md gap-y-xs text-[0.875rem] border-t border-surface-container pt-md">
                <dt class="text-on-surface-variant">Email (sign-in)</dt>
                <dd class="break-all">{{ $user->email }}</dd>
                <dt class="text-on-surface-variant">Role</dt>
                <dd>{{ $user->role->label() }}</dd>
                @if ($user->movement)
                    <dt class="text-on-surface-variant">Movement</dt>
                    <dd>{{ $user->movement->name }}</dd>
                @elseif ($user->isSecretariat())
                    <dt class="text-on-surface-variant">Assesses</dt>
                    <dd>{{ $user->assignedMovements->sortBy('name')->pluck('name')->join(', ') ?: 'No movements yet' }}</dd>
                @endif
            </dl>
            <p class="text-[0.8125rem] text-on-surface-variant">Your email, role and movements are set by the Administrators. Ask one of them if something here is wrong.</p>
        </section>
    </div>
</x-layouts.app>
