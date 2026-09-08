{{--
    SECTION 3 — Features, organised around the wedge.

    Deliberately NOT a generic "invoicing, reporting, dashboards" list. Every
    block is one leg of the actual differentiator (NSSF + EOSI + VAT +
    dual-currency + WhatsApp, self-serve, bilingual), and each carries a tag
    naming what the rest of the category does instead — that comparison is the
    argument, so it sits in the block rather than in a separate table.
--}}
<section id="features" class="bg-warm-offwhite">
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 lg:py-20">
        <div class="max-w-3xl">
            <h2 class="text-2xl font-bold leading-tight tracking-tight text-trust-navy sm:text-3xl lg:text-4xl">
                {{ __('marketing.features.heading') }}
            </h2>
            <p class="mt-4 text-lg leading-relaxed text-warm-charcoal/80">
                {{ __('marketing.features.lede') }}
            </p>
        </div>

        <ul class="mt-10 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            @foreach (__('marketing.features.items') as $item)
                <li>
                    <x-card tone="light" class="flex h-full flex-col">
                        <span aria-hidden="true" class="mb-4 flex size-9 items-center justify-center rounded-lg bg-compliance-green/12">
                            <svg class="size-5 text-compliance-green" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.25">
                                <path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>

                        <h3 class="text-lg font-semibold leading-snug text-trust-navy">{{ $item['title'] }}</h3>
                        <p class="mt-2 flex-1 leading-relaxed text-warm-charcoal/80">{{ $item['body'] }}</p>

                        {{-- The "no competitor offers this combination" line. --}}
                        <p class="mt-4 border-t border-warm-charcoal/10 pt-3 text-sm font-medium text-warm-charcoal/70">
                            {{ $item['tag'] }}
                        </p>
                    </x-card>
                </li>
            @endforeach
        </ul>
    </div>
</section>
