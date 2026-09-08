@props([
    'caption',
    'tone' => 'navy',
    'ratio' => 'aspect-[4/3]',
])

{{--
    PHOTO SLOT — deliberate placeholder, not artwork.

    The design brief wants authentic photography of Lebanese SME environments
    (a restaurant counter, a clinic front desk) and explicitly rules out both
    generic corporate stock and cartoon illustration. There is no shoot yet, so
    these slots hold a flat, palette-only composition that reads as "image
    pending" rather than pretending to be the final image.

    TO REPLACE: swap the <div> below for an <img>/<picture> with the same
    wrapper classes. Nothing else on the page depends on this component's
    internals.

    Composition is left/right symmetric on purpose so it mirrors cleanly under
    RTL. No gradients — flat fills only.
--}}
@php
    $tones = [
        'navy' => ['bg' => 'bg-trust-navy', 'ink' => 'text-warm-offwhite', 'mark' => 'text-warm-gold'],
        'green' => ['bg' => 'bg-compliance-green', 'ink' => 'text-white', 'mark' => 'text-warm-offwhite'],
        'muted' => ['bg' => 'bg-warm-charcoal/8', 'ink' => 'text-warm-charcoal', 'mark' => 'text-trust-navy'],
    ];
    $t = $tones[$tone] ?? $tones['navy'];
@endphp

<figure {{ $attributes->merge(['class' => 'w-full']) }}>
    <div
        class="relative flex w-full items-center justify-center overflow-hidden rounded-xl {{ $ratio }} {{ $t['bg'] }}"
        role="img"
        aria-label="{{ $caption }}"
    >
        <svg
            class="w-1/3 max-w-40 {{ $t['mark'] }}"
            viewBox="0 0 96 72"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            aria-hidden="true"
        >
            <rect x="4" y="10" width="88" height="56" rx="6" opacity=".55" />
            <circle cx="48" cy="32" r="11" />
            <path d="M18 62 L36 44 L48 54 L60 44 L78 62" stroke-linecap="round" stroke-linejoin="round" />
        </svg>

        <span
            class="absolute bottom-0 start-0 end-0 px-4 py-2 text-center text-[0.7rem] font-semibold uppercase tracking-[0.14em] {{ $t['ink'] }} opacity-70"
        >
            {{ __('Photography placeholder') }}
        </span>
    </div>

    <figcaption class="mt-2 text-xs text-warm-charcoal/70">{{ $caption }}</figcaption>
</figure>
