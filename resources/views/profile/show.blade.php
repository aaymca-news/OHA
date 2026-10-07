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
            {{-- Choosing a photo shows it in the frame at once, to be dragged into place and zoomed before saving. --}}
            <form method="POST" action="{{ route('profile.photo') }}" enctype="multipart/form-data" class="flex flex-col gap-sm"
                  x-data="photoCropper" x-on:submit="save($event)">
                @csrf
                <label class="flex flex-col gap-xs text-[0.875rem]">
                    <span class="font-semibold">{{ $user->avatar_path ? 'Replace the photo' : 'Add a photo' }}</span>
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required x-ref="file" x-on:change="choose($event)"
                           class="text-[0.875rem] max-w-full file:mr-sm file:px-md file:py-1.5 file:rounded file:border-[1.5px] file:border-outline-variant file:bg-surface-container-lowest file:text-primary file:font-semibold hover:file:border-primary">
                    <span class="text-[0.8125rem] text-on-surface-variant">A JPG or PNG, up to 5 MB. You can position it before saving.</span>
                </label>
                <p x-show="problem" x-cloak x-text="problem" class="text-[0.875rem] text-error" role="alert"></p>

                <div x-show="image" x-cloak class="flex flex-col gap-sm">
                    <p class="text-[0.875rem] font-semibold">Position your photo</p>
                    <div x-ref="frame" tabindex="0" role="img" aria-label="Photo preview. Drag, or use the arrow keys, to move it; plus and minus to zoom."
                         x-on:pointerdown="start($event)" x-on:pointermove="move($event)" x-on:pointerup="stop()" x-on:pointercancel="stop()" x-on:keydown="key($event)"
                         :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
                         class="relative w-56 h-56 rounded-full overflow-hidden bg-surface-container border-[1.5px] border-outline-variant touch-none select-none focus-visible:outline-2 focus-visible:outline-primary">
                        <img :src="src" alt="" draggable="false" :style="style" class="absolute left-0 top-0 max-w-none origin-top-left pointer-events-none">
                    </div>
                    <label class="flex items-center gap-sm text-[0.875rem] w-56">
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
