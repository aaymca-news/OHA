{{--
    The demo site only: "Choose who to be", in the visitor's own demo. The AAYMCA staff (the
    Administrator, the AAYMCA assessor), or a movement's Board Chairperson or National
    General Secretary. One click each, no password; sign out to come back here.
--}}
@php
    $movements = \App\Models\Movement::query()->orderBy('name')->pluck('name', 'slug');
    $button = 'w-full text-left flex items-center gap-sm p-sm rounded-lg border-[1.5px] border-outline-variant hover:border-primary bg-surface-container-lowest';
@endphp
<div class="flex flex-col gap-md">
    <p class="text-[0.875rem] text-on-surface-variant">
        Everyone is here already, and it is all one demo: what you do as one person, the others see, as on the real platform.
        @if (\App\Demo\Sandboxes::shared())
            Other visitors work in it too.
        @endif
    </p>

    <section class="flex flex-col gap-sm" aria-labelledby="demo-staff">
        <h2 id="demo-staff" class="text-[0.9375rem] font-bold text-primary">AAYMCA staff</h2>
        @foreach (\App\Demo\DemoPeople::STAFF as $key => $person)
            <form method="POST" action="{{ route('demo.sign-in') }}">
                @csrf
                <input type="hidden" name="role" value="{{ $key }}">
                <button type="submit" class="{{ $button }}">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">login</span>
                    <span class="flex flex-col">
                        <span class="text-[0.875rem] font-semibold text-primary">{{ $person['label'] }}</span>
                        <span class="text-[0.8125rem] text-on-surface-variant">{{ $person['explains'] }}</span>
                    </span>
                </button>
            </form>
        @endforeach
    </section>

    <section class="flex flex-col gap-sm" aria-labelledby="demo-movement">
        <h2 id="demo-movement" class="text-[0.9375rem] font-bold text-primary">A National Movement</h2>
        <form method="POST" action="{{ route('demo.sign-in') }}" class="flex flex-col gap-sm">
            @csrf
            <x-select name="movement" label="Movement" :options="$movements" required />
            @foreach (\App\Demo\DemoPeople::MOVEMENT as $key => $person)
                <button type="submit" name="role" value="{{ $key }}" class="{{ $button }}">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">login</span>
                    <span class="flex flex-col">
                        <span class="text-[0.875rem] font-semibold text-primary">{{ $person['label'] }}</span>
                        <span class="text-[0.8125rem] text-on-surface-variant">{{ $person['explains'] }}</span>
                    </span>
                </button>
            @endforeach
        </form>
    </section>
</div>
