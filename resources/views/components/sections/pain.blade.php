{{--
    SECTION 2 — Pain points.

    Speaks to the reality in the knowledge base §4: owner-operated SMEs doing
    the books by hand, or having already paid $5,000–$15,000 for a custom
    system nobody maintains. Names the situation rather than selling at it.
--}}
<section id="pain" class="border-y border-warm-charcoal/10 bg-white">
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 lg:py-20">
        <div class="max-w-3xl">
            <h2 class="text-2xl font-bold leading-tight tracking-tight text-trust-navy sm:text-3xl lg:text-4xl">
                {{ __('marketing.pain.heading') }}
            </h2>
            <p class="mt-4 text-lg leading-relaxed text-warm-charcoal/80">
                {{ __('marketing.pain.lede') }}
            </p>
        </div>

        <ul class="mt-10 grid gap-5 sm:grid-cols-2">
            @foreach (__('marketing.pain.items') as $item)
                <li>
                    <x-card tone="muted" accent="overdue" class="h-full">
                        <h3 class="text-lg font-semibold text-trust-navy">{{ $item['title'] }}</h3>
                        <p class="mt-2 leading-relaxed text-warm-charcoal/80">{{ $item['body'] }}</p>
                    </x-card>
                </li>
            @endforeach
        </ul>
    </div>
</section>
