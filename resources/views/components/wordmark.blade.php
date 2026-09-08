@props(['variant' => 'navy'])

{{--
    PLACEHOLDER WORDMARK — text only, no logo asset.

    The product name is not chosen yet (design brief blocker). When it is,
    swapping in a real logo is a change to THIS FILE ONLY — every header,
    footer and meta reference renders <x-wordmark />, so there is no
    site-wide find-and-replace to do.

    Always set in the Latin typeface, even under an Arabic locale: a wordmark
    is a mark, not body copy, and it should not reflow when the locale flips.
--}}
@php
    $tone = $variant === 'light' ? 'text-warm-offwhite' : 'text-trust-navy';
    $rule = $variant === 'light' ? 'bg-warm-gold' : 'bg-compliance-green';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5 font-sans ' . $tone]) }} dir="ltr">
    <span aria-hidden="true" class="block h-6 w-1.5 shrink-0 rounded-full {{ $rule }}"></span>
    <span class="text-lg font-semibold tracking-tight sm:text-xl">Compliance&nbsp;Suite</span>
</span>
