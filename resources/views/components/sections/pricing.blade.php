{{-- SECTION 5 — Pricing. Figures from knowledge base §7, rendered by the
     shared x-pricing-table component so the app can reuse the same table on
     an in-product upgrade screen. --}}
<section id="pricing" class="border-y border-warm-charcoal/10 bg-white">
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 lg:py-20">
        <div class="max-w-3xl">
            <h2 class="text-2xl font-bold leading-tight tracking-tight text-trust-navy sm:text-3xl lg:text-4xl">
                {{ __('marketing.pricing.heading') }}
            </h2>
            <p class="mt-4 text-lg leading-relaxed text-warm-charcoal/80">
                {{ __('marketing.pricing.lede') }}
            </p>
        </div>

        <x-pricing-table class="mt-10" highlight="growth" cta-href="#early-access" />

        <p class="mt-8 max-w-2xl text-sm leading-relaxed text-warm-charcoal/70">
            {{ __('marketing.pricing.footnote') }}
        </p>
    </div>
</section>
