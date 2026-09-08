@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" class="scroll-pt-20">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ __('marketing.meta.title') }}</title>
    <meta name="description" content="{{ __('marketing.meta.description') }}">
    <meta name="theme-color" content="#0B3B5C">

    <link rel="alternate" hreflang="en" href="{{ route('marketing.home') }}">
    <link rel="alternate" hreflang="ar" href="{{ route('marketing.home.ar') }}">
    <link rel="alternate" hreflang="x-default" href="{{ route('marketing.home') }}">
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ __('marketing.meta.title') }}">
    <meta property="og:description" content="{{ __('marketing.meta.description') }}">
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_LB' : 'en_US' }}">

    {{-- Inter (Latin) + IBM Plex Sans Arabic are self-hosted at build time by
         the Vite fonts plugin (see vite.config.js) — no runtime CDN request on
         a first paint from a phone on mobile data. Vite::fonts() emits the
         @font-face rules inline plus a preload for the first-paint weights, so
         both scripts are ready before the stylesheet lands. --}}
    {{ Vite::fonts() }}

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-warm-offwhite font-sans text-warm-charcoal antialiased">
    <a
        href="#main"
        class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-[60] focus:rounded-lg focus:bg-warm-gold focus:px-4 focus:py-2 focus:font-semibold focus:text-warm-charcoal"
    >{{ __('marketing.nav.skip_to_content') }}</a>

    <x-nav />

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="bg-trust-navy text-warm-offwhite">
        <div class="mx-auto max-w-6xl space-y-6 px-4 py-12 sm:px-6">
            <x-wordmark variant="light" />
            <p class="max-w-xl text-base text-warm-offwhite/85">{{ __('marketing.footer.tagline') }}</p>

            {{-- Legal: text-only reference to NSSF / MoF. No seals, logos or
                 government imagery anywhere on this site — that needs a legal
                 check that has not happened. See docs/CONTENT-TODO.md. --}}
            <p class="max-w-2xl border-t border-warm-offwhite/20 pt-6 text-sm text-warm-offwhite/70">
                {{ __('marketing.footer.disclaimer') }}
            </p>
            <p class="text-sm text-warm-offwhite/70">
                &copy; {{ date('Y') }} &middot; {{ __('marketing.footer.rights') }}
            </p>
        </div>
    </footer>
</body>
</html>
