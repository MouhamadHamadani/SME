{{--
    SECTION 6 — Founder story.

    Stands in for testimonials that cannot exist before launch. First person,
    short, no claims that need proof — the credibility here comes from naming
    the problem accurately, not from a quote.

    PLACEHOLDER COPY: structured and voiced for real, but the specifics (years,
    the businesses worked with, the signature) need the actual founder. See
    docs/CONTENT-TODO.md.
--}}
<section id="story" class="bg-warm-offwhite">
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 lg:grid lg:grid-cols-12 lg:gap-12 lg:py-20">
        <div class="lg:col-span-7">
            <p class="text-sm font-semibold uppercase tracking-wider text-compliance-green">
                {{ __('marketing.story.eyebrow') }}
            </p>

            <h2 class="mt-3 text-2xl font-bold leading-tight tracking-tight text-trust-navy sm:text-3xl lg:text-4xl">
                {{ __('marketing.story.heading') }}
            </h2>

            <div class="mt-6 space-y-5 border-s-4 border-warm-gold ps-6">
                @foreach (__('marketing.story.body') as $paragraph)
                    <p class="text-lg leading-relaxed text-warm-charcoal/85">{{ $paragraph }}</p>
                @endforeach
            </div>

            <p class="mt-6 text-base font-semibold text-trust-navy">{{ __('marketing.story.signature') }}</p>
        </div>

        <div class="mt-10 lg:col-span-5 lg:mt-0 lg:self-center">
            {{-- PHOTO SLOT: founder portrait or clinic front desk, story section --}}
            <x-photo-slot :caption="__('marketing.story.photo_slot')" tone="muted" ratio="aspect-square" />
        </div>
    </div>
</section>
