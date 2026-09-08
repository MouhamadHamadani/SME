@props([
    'name',
    'label',
    'type' => 'text',
    'hint' => null,
    'optional' => null,
    'required' => false,
    'autocomplete' => null,
])

@php
    $error = $errors->first($name);

    $control = 'w-full rounded-lg border-2 bg-white px-4 py-3 text-base text-warm-charcoal '
        . 'placeholder:text-warm-charcoal/40 transition-colors '
        . 'focus:outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold '
        . ($error
            ? 'border-status-overdue focus:border-status-overdue'
            : 'border-warm-charcoal/20 focus:border-trust-navy');

    $described = $error ? $name . '-error' : ($hint ? $name . '-hint' : null);
@endphp

<x-form.field
    :name="$name"
    :label="$label"
    :hint="$hint"
    :optional="$optional"
    :required="$required"
    {{ $attributes->only('class') }}
>
    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name) }}"
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($required) required aria-required="true" @endif
        @if ($error) aria-invalid="true" @endif
        @if ($described) aria-describedby="{{ $described }}" @endif
        {{ $attributes->except('class')->merge(['class' => $control]) }}
    >
</x-form.field>
