@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'size' => 'md',
])

{{--
    Button — shared by the marketing site and the app.

    Variants:
      cta       warm-gold ground, charcoal text (5.7:1). The gold accent, used
                sparingly: this is the one variant that spends brand colour.
      primary   trust-navy ground, offwhite text (11:1). Structural default.
      secondary transparent with a navy border. Sits beside primary.
      ghost     text only, for tertiary actions.

    Gold is never used as *text* on a light ground — #C9971F on #F7F5F1 is
    2.4:1 and fails AA. It only ever appears as a filled ground under dark text.

    Renders <a> when :href is given, <button> otherwise.
--}}
@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-semibold '
        . 'transition-colors duration-150 focus-visible:outline-3 focus-visible:outline-offset-2 '
        . 'disabled:cursor-not-allowed disabled:opacity-60 text-center';

    $sizes = [
        'sm' => 'px-4 py-2 text-sm',
        'md' => 'px-5 py-3 text-base',
        'lg' => 'px-7 py-4 text-base sm:text-lg',
    ];

    $variants = [
        'cta' => 'bg-warm-gold text-warm-charcoal hover:bg-warm-gold/85 focus-visible:outline-trust-navy',
        'primary' => 'bg-trust-navy text-warm-offwhite hover:bg-trust-navy/90 focus-visible:outline-warm-gold',
        'secondary' => 'border-2 border-trust-navy text-trust-navy hover:bg-trust-navy hover:text-warm-offwhite focus-visible:outline-warm-gold',
        'ghost' => 'text-trust-navy underline-offset-4 hover:underline focus-visible:outline-warm-gold',
    ];

    $classes = implode(' ', [$base, $sizes[$size] ?? $sizes['md'], $variants[$variant] ?? $variants['primary']]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
