{{--
    A form that asks "are you sure?" before it is sent: delete, withdraw, discard.
      action   where it posts
      method   POST (default), or DELETE/PUT/PATCH (sent as _method)
      confirm  the question, shown in the browser's confirm box
    The slot holds the fields and the button.
--}}
@props(['action', 'method' => 'POST', 'confirm'])
<form method="POST" action="{{ $action }}" x-data x-on:submit="if (! confirm(@js($confirm))) $event.preventDefault()" {{ $attributes }}>
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method(strtoupper($method))
    @endif
    {{ $slot }}
</form>
