{{-- Starting a movement's next assessment: its period and starting month. Expects $movement. --}}
<form method="POST" action="{{ route('assessments.store', $movement) }}" class="flex flex-wrap items-end gap-sm mt-sm">
    @csrf
    <x-input name="period_label" label="Period" :value="now()->asMonth()" class="w-32" required />
    <x-input name="period_start" label="Starting month" type="month" :value="now()->format('Y-m')" required />
    <button type="submit" class="inline-flex items-center gap-sm min-h-10 px-[16px] rounded-[10px] bg-on-surface text-white text-[0.875rem] font-semibold hover:bg-black focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
        Start new assessment
    </button>
</form>
