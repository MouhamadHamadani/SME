@props([
    'name',
    'label',
    'hint' => null,
    'optional' => null,
    'required' => false,
])

{{--
    Field wrapper — label, optional hint, and the error message for :name.
    x-form.input and x-form.select both render through this, so the validation
    state looks identical whatever the control is.
--}}
@php
    $error = $errors->first($name);
@endphp

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    <label for="{{ $name }}" class="flex flex-wrap items-baseline gap-x-2 text-sm font-semibold text-warm-charcoal">
        <span>{{ $label }}</span>
        @if ($optional)
            <span class="text-xs font-normal text-warm-charcoal/70">{{ $optional }}</span>
        @endif
        @if ($required)
            <span class="text-status-overdue" aria-hidden="true">*</span>
        @endif
    </label>

    {{ $slot }}

    @if ($hint && ! $error)
        <p id="{{ $name }}-hint" class="text-xs text-warm-charcoal/70">{{ $hint }}</p>
    @endif

    @if ($error)
        <p id="{{ $name }}-error" class="text-sm font-medium text-status-overdue">{{ $error }}</p>
    @endif
</div>
