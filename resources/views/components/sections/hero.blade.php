{{--
    SECTION 1 — Hero.

    The price is the point. Every competitor in this category gates pricing
    behind a sales call, so "$19/month", legible without scrolling or filling
    anything in, is the single strongest signal on the page. It is sized to
    compete with the headline rather than sit under it as a footnote, and it
    comes before the CTAs in source order so it is read first on a phone.
--}}
<section class="bg-warm-offwhite">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:grid lg:grid-cols-12 lg:items-center lg:gap-12 lg:py-20">
        <div class="lg:col-span-7">
            {{-- Solid green, white text (5.3:1). A green-on-green-tint chip
                 measures 4.1:1 and fails AA at this size, so the tint version
                 is not an option here. --}}
            <p class="inline-flex items-center rounded-full bg-compliance-green px-3 py-1 text-sm font-semibold text-white">
                {{ __('marketing.hero.eyebrow') }}
            </p>

            <h1 class="mt-5 text-3xl font-bold leading-tight tracking-tight text-trust-navy sm:text-4xl lg:text-5xl">
                {{ __('marketing.hero.heading') }}
            </h1>

            <p class="mt-4 max-w-2xl text-lg leading-relaxed text-warm-charcoal/85">
                {{ __('marketing.hero.subheading') }}
            </p>

            {{-- The differentiator. Gold rule on the start edge (border-s), so
                 it mirrors with the document instead of pinning itself left. --}}
            <div id="hero-price" class="mt-7 rounded-xl border-s-4 border-warm-gold bg-white p-5 ring-1 ring-warm-charcoal/10 sm:p-6">
                <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                    {{ __('marketing.hero.price_label') }}
                </p>
                <p class="mt-1 flex flex-wrap items-baseline gap-x-1 text-trust-navy">
                    <span class="text-5xl font-bold tracking-tight sm:text-6xl">{{ __('marketing.hero.price_amount') }}</span>
                    <span class="text-xl font-semibold text-warm-charcoal/70">{{ __('marketing.hero.price_period') }}</span>
                </p>
                <p class="mt-3 text-base font-medium text-warm-charcoal/80">
                    {{ __('marketing.hero.price_note') }}
                </p>
            </div>

            <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center">
                <x-button href="#early-access" variant="cta" size="lg">
                    {{ __('marketing.hero.cta_primary') }}
                </x-button>
                <x-button href="#pricing" variant="secondary" size="lg">
                    {{ __('marketing.hero.cta_secondary') }}
                </x-button>
            </div>
        </div>

        <div class="mt-10 lg:col-span-5 lg:mt-0">
            {{-- PHOTO SLOT: restaurant counter, hero section --}}
            <x-photo-slot :caption="__('marketing.hero.photo_slot')" tone="navy" ratio="aspect-[4/3]" />
        </div>
    </div>
</section>
