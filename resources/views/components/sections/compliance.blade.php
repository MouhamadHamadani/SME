{{--
    SECTION 4 — Compliance / trust.

    Deliberately its own visually distinct module rather than another cell in
    the feature grid: this is the core promise of the product, and after the
    hero it gets the most design weight on the page. It inverts to the navy
    ground — a flat fill, no gradient — so it reads as a separate surface, and
    it shows the real status-badge component rather than describing it.

    Legal: NSSF / Ministry of Finance are referenced in words only. No seals,
    no logos, no government imagery anywhere — that check has not happened yet.
--}}
<section id="compliance" class="bg-trust-navy text-warm-offwhite">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
        <div class="max-w-3xl">
            <p class="inline-flex items-center rounded-full bg-warm-gold px-3 py-1 text-sm font-semibold text-warm-charcoal">
                {{ __('marketing.compliance.eyebrow') }}
            </p>

            <h2 class="mt-5 text-2xl font-bold leading-tight tracking-tight sm:text-3xl lg:text-4xl">
                {{ __('marketing.compliance.heading') }}
            </h2>
            <p class="mt-5 text-lg leading-relaxed text-warm-offwhite/85">
                {{ __('marketing.compliance.lede') }}
            </p>
        </div>

        <div class="mt-12 grid gap-10 lg:grid-cols-12 lg:gap-12">
            <ul class="space-y-5 lg:col-span-7">
                @foreach (__('marketing.compliance.items') as $item)
                    <li class="border-s-4 border-compliance-green ps-5">
                        <h3 class="text-lg font-semibold">{{ $item['title'] }}</h3>
                        <p class="mt-1.5 leading-relaxed text-warm-offwhite/80">{{ $item['body'] }}</p>
                    </li>
                @endforeach
            </ul>

            {{-- Live status-badge component — the same one the app renders on
                 real filing periods. Showing it here is the proof, not a mock. --}}
            <div class="lg:col-span-5">
                <div class="rounded-xl bg-warm-offwhite p-6 text-warm-charcoal">
                    <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                        {{ __('marketing.compliance.status_demo_label') }}
                    </p>

                    <ul class="mt-4 space-y-3">
                        @foreach ([
                            ['filed', 'status_filed', 'status_filed_context'],
                            ['due-soon', 'status_due_soon', 'status_due_soon_context'],
                            ['overdue', 'status_overdue', 'status_overdue_context'],
                        ] as [$status, $labelKey, $contextKey])
                            <li class="flex flex-wrap items-center justify-between gap-3 border-b border-warm-charcoal/10 pb-3 last:border-0 last:pb-0">
                                <span class="font-semibold">{{ __('marketing.compliance.' . $contextKey) }}</span>
                                <x-status-badge :status="$status">
                                    {{ __('marketing.compliance.' . $labelKey) }}
                                </x-status-badge>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="mt-4 text-sm leading-relaxed text-warm-offwhite/65">
                    {{ __('marketing.compliance.disclaimer') }}
                </p>
            </div>
        </div>
    </div>
</section>
