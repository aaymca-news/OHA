{{-- A workflow state, including the derived "locked". --}}
@props(['state'])
@php($display = \App\Enums\ArtefactState::display($state instanceof \BackedEnum ? $state->value : (string) $state))
<x-chip :tone="$display['tone']" :icon="$display['icon']" {{ $attributes }}>{{ $display['label'] }}</x-chip>
