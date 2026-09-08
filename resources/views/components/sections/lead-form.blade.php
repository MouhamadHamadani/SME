{{--
    Lead capture.

    There is no self-serve billing yet — Whish/OMT is Phase 2 (§6) — so
    "signup" here means lead capture, not account creation. Rows land in the
    `leads` table with no tenant_id: this is pre-tenant, and the marketing
    routes sit outside all tenancy/auth middleware.
--}}
<section id="early-access" class="scroll-mt-20 bg-trust-navy text-warm-offwhite">
    <div class="mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:py-20">
        @if (session('lead_submitted'))
            {{-- Thank-you state. A real rendered state, not an alert(): it keeps
                 the page, the language and the layout, and it says what happens
                 next rather than just acknowledging the click. --}}
            <div class="rounded-xl bg-warm-offwhite p-7 text-warm-charcoal sm:p-9" role="status" aria-live="polite">
                <span aria-hidden="true" class="flex size-12 items-center justify-center rounded-full bg-compliance-green">
                    <svg class="size-6 text-white" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>

                <h2 class="mt-5 text-2xl font-bold leading-tight text-trust-navy sm:text-3xl">
                    {{ __('marketing.form.thanks_heading') }}
                </h2>
                <p class="mt-3 text-lg leading-relaxed text-warm-charcoal/85">
                    {{ __('marketing.form.thanks_body') }}
                </p>
                <p class="mt-4 border-s-4 border-warm-gold ps-4 leading-relaxed text-warm-charcoal/75">
                    {{ __('marketing.form.thanks_secondary') }}
                </p>
            </div>
        @else
            <div class="max-w-2xl">
                {{-- Gold as a fill under charcoal (5.7:1), matching the
                     compliance eyebrow. Gold *text* on navy measures 4.4:1 and
                     misses AA at this size. --}}
                <p class="inline-flex items-center rounded-full bg-warm-gold px-3 py-1 text-sm font-semibold text-warm-charcoal">
                    {{ __('marketing.form.eyebrow') }}
                </p>
                <h2 class="mt-3 text-2xl font-bold leading-tight tracking-tight sm:text-3xl lg:text-4xl">
                    {{ __('marketing.form.heading') }}
                </h2>
                <p class="mt-4 text-lg leading-relaxed text-warm-offwhite/85">
                    {{ __('marketing.form.lede') }}
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('marketing.leads.store') }}"
                class="mt-8 rounded-xl bg-warm-offwhite p-6 sm:p-8"
                novalidate
            >
                @csrf
                {{-- Locale travels with the submission so validation messages and
                     the thank-you state come back in the language they were
                     filled in, whichever URL the POST lands on. --}}
                <input type="hidden" name="locale" value="{{ app()->getLocale() }}">

                @if ($errors->any())
                    <p class="mb-6 rounded-lg border-s-4 border-status-overdue bg-status-overdue/8 px-4 py-3 font-semibold text-warm-charcoal">
                        {{ __('marketing.form.error_heading') }}
                    </p>
                @endif

                {{-- Honeypot. Hidden from people, irresistible to naive bots.
                     Kept out of the tab order and off the a11y tree; a filled
                     value is dropped silently in LeadController.

                     Hidden with `sr-only` (clip + 1px box), NOT the usual
                     `left:-9999px`. Under RTL a large negative offset overflows
                     in the scrollable direction: it stretched the Arabic
                     document to ~10,000px and pushed every section off-screen.
                     Never park hidden fields at a negative offset on a
                     bidirectional page. --}}
                <div aria-hidden="true" class="sr-only">
                    <label for="website">{{ __('marketing.form.honeypot_label') }}</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-form.input
                        name="name"
                        :label="__('marketing.form.name')"
                        autocomplete="name"
                        required
                    />
                    <x-form.input
                        name="business_name"
                        :label="__('marketing.form.business_name')"
                        autocomplete="organization"
                        required
                    />
                    <x-form.input
                        name="phone"
                        type="tel"
                        :label="__('marketing.form.phone')"
                        :hint="__('marketing.form.phone_hint')"
                        autocomplete="tel"
                        required
                    />
                    <x-form.input
                        name="email"
                        type="email"
                        :label="__('marketing.form.email')"
                        autocomplete="email"
                        required
                    />
                    <x-form.select
                        name="business_type"
                        :label="__('marketing.form.business_type')"
                        :placeholder="__('marketing.form.business_type_placeholder')"
                        :options="__('marketing.form.business_types')"
                        required
                    />
                    <x-form.select
                        name="currently_using"
                        :label="__('marketing.form.currently_using')"
                        :optional="__('marketing.form.currently_using_optional')"
                        :placeholder="__('marketing.form.currently_using_placeholder')"
                        :options="__('marketing.form.currently_using_options')"
                    />
                </div>

                <div class="mt-7 flex flex-col gap-4 sm:flex-row sm:items-center">
                    <x-button type="submit" variant="cta" size="lg" class="w-full sm:w-auto">
                        {{ __('marketing.form.submit') }}
                    </x-button>
                    <p class="text-sm leading-relaxed text-warm-charcoal/70">
                        {{ __('marketing.form.privacy') }}
                    </p>
                </div>
            </form>
        @endif
    </div>
</section>
