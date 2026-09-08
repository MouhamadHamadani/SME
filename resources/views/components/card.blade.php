@props([
    'tone' => 'light',
    'accent' => null,
    'padded' => true,
])

{{--
    Card — the one surface primitive. Used by feature blocks, pain points,
    compliance points and the pricing tiers.

    :accent draws a rule along the block's START edge (ps/border-s), so it
    mirrors correctly under RTL instead of pinning itself to the left.
    Allowed accents: green | gold | navy | due-soon | overdue.
--}}
@php
    $tones = [
        'light' => 'bg-white text-warm-charcoal ring-1 ring-warm-charcoal/10',
        'muted' => 'bg-warm-offwhite text-warm-charcoal ring-1 ring-warm-charcoal/10',
        'navy' => 'bg-trust-navy text-warm-offwhite ring-1 ring-white/10',
    ];

    $accents = [
        'green' => 'border-s-4 border-compliance-green',
        'gold' => 'border-s-4 border-warm-gold',
        'navy' => 'border-s-4 border-trust-navy',
        'due-soon' => 'border-s-4 border-status-due-soon',
        'overdue' => 'border-s-4 border-status-overdue',
    ];

    $classes = implode(' ', array_filter([
        'rounded-xl',
        $padded ? 'p-6 sm:p-7' : '',
        $tones[$tone] ?? $tones['light'],
        $accent ? ($accents[$accent] ?? '') : '',
    ]));
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</div>
