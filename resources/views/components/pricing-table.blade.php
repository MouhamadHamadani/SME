@props([
    'highlight' => 'growth',
    'ctaHref' => '#early-access',
])

{{--
    Pricing table.

    Figures come from lang/*/marketing.php (knowledge base §7) so the two
    locales can never drift apart on price:
        Starter $19  · up to 5 users, invoicing + VAT
        Growth  $49  · up to 20 employees, adds payroll/NSSF
        Pro     $99  · multi-branch, multi-currency reporting, accountant seats

    Real numbers, printed. The whole positioning rests on not hiding these
    behind a "contact us", so there is no "starting at" hedge and no quote form.
--}}
@php
    $tiers = __('marketing.pricing.tiers');
@endphp

<div {{ $attributes->merge(['class' => 'grid gap-6 lg:grid-cols-3']) }}>
    @foreach ($tiers as $key => $tier)
        @php $isHighlight = $key === $highlight; @endphp

        <div
            class="relative flex flex-col rounded-xl bg-white p-6 sm:p-7 {{ $isHighlight ? 'ring-2 ring-trust-navy' : 'ring-1 ring-warm-charcoal/12' }}"
        >
            @if ($isHighlight)
                <p class="mb-4 inline-flex self-start rounded-full bg-warm-gold px-3 py-1 text-sm font-semibold text-warm-charcoal">
                    {{ __('marketing.pricing.popular') }}
                </p>
            @endif

            <h3 class="text-xl font-bold text-trust-navy">{{ $tier['name'] }}</h3>

            <p class="mt-3 flex flex-wrap items-baseline gap-x-1">
                <span class="text-4xl font-bold tracking-tight text-trust-navy">{{ $tier['price'] }}</span>
                <span class="text-base font-medium text-warm-charcoal/70">{{ __('marketing.pricing.per_month') }}</span>
            </p>

            <p class="mt-3 leading-relaxed text-warm-charcoal/80">{{ $tier['summary'] }}</p>

            <ul class="mt-6 flex-1 space-y-2.5">
                @foreach ($tier['features'] as $feature)
                    <li class="flex items-start gap-2.5">
                        <svg
                            class="mt-1 size-4 shrink-0 text-compliance-green"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.5"
                            aria-hidden="true"
                        >
                            <path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span class="text-warm-charcoal/90">{{ $feature }}</span>
                    </li>
                @endforeach
            </ul>

            <x-button
                :href="$ctaHref"
                :variant="$isHighlight ? 'cta' : 'secondary'"
                size="md"
                class="mt-7 w-full"
            >
                {{ __('marketing.pricing.cta') }}
            </x-button>
        </div>
    @endforeach
</div>
