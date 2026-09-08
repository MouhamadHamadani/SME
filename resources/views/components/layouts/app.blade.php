@props([
    'title' => null,
    'chrome' => true,
])

@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
    $business = tenant('name');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ? $title.' · '.$business : $business }}</title>
    <meta name="theme-color" content="#0B3B5C">

    {{-- Same tokens, same fonts, same components as the marketing site. That is
         the whole reason the design system was built there first. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-warm-offwhite font-sans text-warm-charcoal antialiased">
    <a
        href="#main"
        class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-50 focus:rounded-lg focus:bg-warm-gold focus:px-4 focus:py-2 focus:font-semibold focus:text-warm-charcoal"
    >{{ __('app.nav.skip_to_content') }}</a>

    @if ($chrome)
        <header class="bg-trust-navy">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
                <a href="{{ route('app.dashboard') }}" class="shrink-0 rounded">
                    <x-wordmark variant="light" />
                </a>

                <span class="hidden text-sm text-warm-offwhite/70 sm:inline" aria-hidden="true">/</span>
                <span class="text-sm font-semibold text-warm-offwhite">{{ $business }}</span>

                <nav class="ms-4 flex flex-wrap items-center gap-x-4 gap-y-1" aria-label="{{ __('app.nav.dashboard') }}">
                    @foreach (['app.dashboard' => 'dashboard', 'app.invoices.index' => 'invoices', 'app.vat' => 'vat', 'app.payroll' => 'payroll', 'app.employees.index' => 'employees', 'app.customers.index' => 'customers'] as $route => $key)
                        <a
                            href="{{ route($route) }}"
                            @class(['rounded text-sm underline-offset-4 hover:underline', 'font-semibold text-warm-offwhite' => request()->routeIs($route), 'text-warm-offwhite/85' => ! request()->routeIs($route)])
                            @if (request()->routeIs($route)) aria-current="page" @endif
                        >{{ __('app.nav.'.$key) }}</a>
                    @endforeach
                </nav>

                <div class="ms-auto flex items-center gap-2">
                    {{-- Locale is a POST, not a link: it writes the person's
                         preference, so it must not be a GET a crawler can trip. --}}
                    <form method="POST" action="{{ route('app.locale') }}">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $locale === 'ar' ? 'en' : 'ar' }}">
                        <button
                            type="submit"
                            lang="{{ $locale === 'ar' ? 'en' : 'ar' }}"
                            aria-label="{{ __('app.nav.switch_language_aria') }}"
                            class="rounded-lg border border-warm-offwhite/35 px-3 py-2 text-sm font-semibold text-warm-offwhite transition-colors hover:border-warm-offwhite hover:bg-warm-offwhite/10"
                        >{{ __('app.nav.switch_language') }}</button>
                    </form>

                    <form method="POST" action="{{ route('app.logout') }}">
                        @csrf
                        <x-button type="submit" variant="cta" size="sm">
                            {{ __('app.nav.sign_out') }}
                        </x-button>
                    </form>
                </div>
            </div>
        </header>
    @endif

    <main id="main" class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        {{ $slot }}
    </main>
</body>
</html>
