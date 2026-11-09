<x-layouts.app :title="__('oha.nav.profile')" subtitle="Your photo and details, the text size, and the devices you are signed in on. Your password is under Security.">
    <div class="grid lg:grid-cols-2 gap-md items-start">
        {{-- Profile photo: shown wherever the person's name appears. --}}
        <x-card title="Profile photo" subtitle="Shown beside your name across the platform: the top bar, Users & Roles, and the movements you assess or represent." padding="lg" class="min-w-0">
            <div class="flex flex-wrap items-center gap-md">
                <x-avatar :user="$user" size="lg" />
                <div class="flex flex-col gap-xs text-[0.875rem]">
                    <span class="font-semibold">{{ $user->name }}</span>
                    <span class="text-on-surface-variant">{{ $user->avatar_path ? 'Your photo' : 'No photo yet: your initials show instead.' }}</span>
                </div>
            </div>
            {{-- Choosing a photo shows it in the frame at once, to be dragged into place and zoomed before saving. --}}
            <form method="POST" action="{{ route('profile.photo') }}" enctype="multipart/form-data" class="flex flex-col gap-sm"
                  x-data="photoCropper" x-on:submit="save($event)">
                @csrf
                {{-- A plain file input (not x-file-drop): the cropper reads it through x-ref="file" and choose(). --}}
                <x-field :label="$user->avatar_path ? 'Replace the photo' : 'Add a photo'" name="photo" :hint="'A JPG or PNG, up to '.\App\Support\Viz::figure(min((int) config('oha.max_photo_kb'), \App\Support\UploadLimit::kb()) / 1024, 1).' MB. You can position it before saving.'">
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required x-ref="file" x-on:change="choose($event)"
                           class="text-[0.875rem] max-w-full file:mr-sm file:px-md file:py-1.5 file:rounded file:border-[1.5px] file:border-outline-variant file:bg-surface-container-lowest file:text-primary file:font-semibold hover:file:border-primary">
                </x-field>
                <p x-show="problem" x-cloak x-text="problem" class="text-[0.875rem] text-error" role="alert"></p>

                <div x-show="image" x-cloak class="flex flex-col gap-sm">
                    <p class="text-[0.875rem] font-semibold">Position your photo</p>
                    <div x-ref="frame" tabindex="0" role="img" aria-label="Photo preview. Drag, or use the arrow keys, to move it; plus and minus to zoom."
                         x-on:pointerdown="start($event)" x-on:pointermove="move($event)" x-on:pointerup="stop()" x-on:pointercancel="stop()" x-on:keydown="key($event)"
                         :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
                         class="relative w-56 max-w-full aspect-square rounded-full overflow-hidden bg-surface-container border-[1.5px] border-outline-variant touch-none select-none focus-visible:outline-2 focus-visible:outline-primary">
                        <img :src="src" alt="" draggable="false" :style="style" class="absolute left-0 top-0 max-w-none origin-top-left pointer-events-none">
                    </div>
                    <label class="flex items-center gap-sm text-[0.875rem] w-56 max-w-full">
                        <span class="material-symbols-outlined text-[1.125rem] text-on-surface-variant" aria-hidden="true">zoom_out</span>
                        <input type="range" min="1" max="4" step="0.01" :value="zoom" x-on:input="setZoom($event.target.value)" aria-label="Zoom" class="flex-1 accent-primary">
                        <span class="material-symbols-outlined text-[1.125rem] text-on-surface-variant" aria-hidden="true">zoom_in</span>
                    </label>
                    <p class="text-[0.8125rem] text-on-surface-variant">Drag the photo to move it; use the slider to zoom. What is inside the circle is what others see.</p>
                </div>
                <x-button class="self-start">Save photo</x-button>
            </form>
            @if ($user->avatar_path)
                <form method="POST" action="{{ route('profile.photo.remove') }}">
                    @csrf
                    @method('DELETE')
                    <x-button variant="link" class="text-[0.875rem]">Remove the photo</x-button>
                </form>
            @endif
        </x-card>

        {{-- Details: the name and title are theirs to correct; the rest is set by the Administrators. --}}
        <x-card title="Your details" padding="lg" class="min-w-0">
            <form method="POST" action="{{ route('profile.update') }}" class="flex flex-col gap-sm">
                @csrf
                @method('PUT')
                <x-input name="name" label="Full name" :value="$user->name" required autocomplete="name" />
                {{-- A National Movement's Board Chairperson and General Secretary hold the title of their role. --}}
                @if ($user->isSecretariat())
                    <x-input name="title" label="Job title" :value="$user->title" autocomplete="organization-title" hint="For example: Zonal Coordinator, East Africa" />
                @endif
                <x-button class="self-start">Save details</x-button>
            </form>

            <dl class="grid grid-cols-[auto_1fr] gap-x-md gap-y-xs text-[0.875rem] border-t border-surface-container pt-md">
                @unless ($user->isSecretariat())
                    <dt class="text-on-surface-variant">Job title</dt>
                    <dd>{{ $user->title ?: $user->role->label() }}</dd>
                @endunless
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
            <p class="text-[0.8125rem] text-on-surface-variant">
                Your email, {{ $user->isSecretariat() ? 'role and the movements you assess' : 'job title and role' }} are set by the Administrators. Ask one of them if something here is wrong.
            </p>
        </x-card>

        {{-- How the platform looks on this device. --}}
        <x-card title="Display" subtitle="Make the text larger or smaller everywhere in the platform. It is remembered on this device; the same slider is in the menu under your name." padding="lg" class="min-w-0">
            @include('partials.text-size', ['id' => 'text-scale-profile'])
        </x-card>

        @include('security._devices')
    </div>
</x-layouts.app>
