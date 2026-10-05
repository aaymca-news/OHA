@php
    $messages = [
        'password-updated' => 'Your password has been changed.',
    ];
    $status = session('status');
@endphp

@if ($status)
    <div role="status" class="p-sm rounded border-[1.5px] border-band-strong bg-surface-container-lowest text-[0.875rem]">
        {{ $messages[$status] ?? $status }}
    </div>
@endif

@if ($errors->any())
    <div role="alert" class="p-sm rounded border-[1.5px] border-error bg-error-container text-on-error-container text-[0.875rem]">
        <ul class="list-disc pl-md">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
