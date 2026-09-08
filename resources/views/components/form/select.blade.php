@props([
    'name',
    'label',
    'options' => [],
    'placeholder' => null,
    'hint' => null,
    'optional' => null,
    'required' => false,
    // Current selection for an edit form; a failed submission still wins.
    'selected' => null,
])

@php
    $error = $errors->first($name);

    // No custom chevron background-image: a background-position would need
    // mirroring under RTL. The native select control flips itself.
    $control = 'w-full appearance-none rounded-lg border-2 bg-white px-4 py-3 text-base text-warm-charcoal '
        . 'transition-colors focus:outline-none focus-visible:outline-3 focus-visible:outline-offset-2 '
        . 'focus-visible:outline-warm-gold '
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
    <select
        id="{{ $name }}"
        name="{{ $name }}"
        @if ($required) required aria-required="true" @endif
        @if ($error) aria-invalid="true" @endif
        @if ($described) aria-describedby="{{ $described }}" @endif
        {{ $attributes->except('class')->merge(['class' => $control]) }}
    >
        <option value="">{{ $placeholder }}</option>
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) old($name, $selected) === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>
</x-form.field>
