{{-- A workflow state, including the derived "locked", as a status pill (design standard of 10 Nov 2026). --}}
@props(['state'])
@php($display = \App\Enums\ArtefactState::display($state instanceof \BackedEnum ? $state->value : (string) $state))
<x-status-pill :tone="$display['tone']" {{ $attributes }}>{{ $display['label'] }}</x-status-pill>
