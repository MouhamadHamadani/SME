@php
    // The switcher always points at the *other* locale's home URL. Locale lives
    // in the URL rather than the session so a link someone shares (or an ad
    // lands on) carries its language with it.
    $isArabic = app()->getLocale() === 'ar';
    $altUrl = $isArabic ? route('marketing.home') : route('marketing.home.ar');
    $altLang = $isArabic ? 'en' : 'ar';

    $links = [
        '#features' => __('marketing.nav.features'),
        '#compliance' => __('marketing.nav.compliance'),
        '#pricing' => __('marketing.nav.pricing'),
        '#story' => __('marketing.nav.story'),
    ];
@endphp

<header class="sticky top-0 z-50 bg-trust-navy">
    <nav class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3 sm:px-6 lg:py-4" aria-label="{{ __('marketing.nav.menu') }}">
        <a href="{{ $isArabic ? route('marketing.home.ar') : route('marketing.home') }}" class="shrink-0 rounded">
            <x-wordmark variant="light" />
        </a>

        {{-- Desktop links. Sits at the far end of the bar; `ms-auto` is logical,
             so it lands on the right in LTR and the left in RTL. --}}
        <ul class="ms-auto hidden items-center gap-6 lg:flex">
            @foreach ($links as $href => $label)
                <li>
                    <a
                        href="{{ $href }}"
                        class="rounded text-sm font-medium text-warm-offwhite/85 underline-offset-4 transition-colors hover:text-warm-offwhite hover:underline"
                    >{{ $label }}</a>
                </li>
            @endforeach
        </ul>

        <div class="ms-auto flex items-center gap-2 lg:ms-0 lg:gap-3">
            <a
                href="{{ $altUrl }}"
                lang="{{ $altLang }}"
                hreflang="{{ $altLang }}"
                aria-label="{{ __('marketing.nav.switch_language_aria') }}"
                class="rounded-lg border border-warm-offwhite/35 px-3 py-2 text-sm font-semibold text-warm-offwhite transition-colors hover:border-warm-offwhite hover:bg-warm-offwhite/10"
            >{{ __('marketing.nav.switch_language') }}</a>

            {{-- Wrapped rather than given `hidden sm:inline-flex` directly:
                 x-button already carries `inline-flex`, and Tailwind emits it
                 after `hidden`, so the utility on the component would lose the
                 cascade and the button would show at every width. Below sm the
                 CTA lives in the disclosure below instead. --}}
            <span class="hidden sm:block">
                <x-button href="#early-access" variant="cta" size="sm">
                    {{ __('marketing.nav.cta') }}
                </x-button>
            </span>
        </div>
    </nav>

    {{-- Mobile disclosure. Native <details> — no JS, and it degrades to an
         always-open list if scripting or CSS is unavailable. --}}
    <details class="border-t border-warm-offwhite/15 lg:hidden">
        <summary
            class="mx-auto flex max-w-6xl cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-semibold text-warm-offwhite sm:px-6"
        >
            {{ __('marketing.nav.menu') }}
            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M5 8l5 5 5-5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </summary>

        <ul class="mx-auto max-w-6xl space-y-1 px-4 pb-4 sm:px-6">
            @foreach ($links as $href => $label)
                <li>
                    <a href="{{ $href }}" class="block rounded-lg px-3 py-2.5 text-base font-medium text-warm-offwhite/90 hover:bg-warm-offwhite/10">
                        {{ $label }}
                    </a>
                </li>
            @endforeach
            <li class="pt-2">
                <x-button href="#early-access" variant="cta" size="md" class="w-full">
                    {{ __('marketing.nav.cta') }}
                </x-button>
            </li>
        </ul>
    </details>
</header>
