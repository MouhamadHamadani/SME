@php
    $tenant = tenant();
    $settings = $tenant->settings;
    $rate = $settings['exchange_rate'] ?? null;
@endphp

<x-layouts.app :title="__('app.nav.dashboard')">
    <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
        {{ __('app.dashboard.heading', ['name' => auth()->user()->name]) }}
    </h1>
    <p class="mt-2 text-lg text-warm-charcoal/80">
        {{ __('app.dashboard.lede', [
            'business' => $tenant->name,
            'plan' => __('app.plans.'.$tenant->plan),
        ]) }}
    </p>

    <div class="mt-8 grid gap-5 sm:grid-cols-3">
        <x-card tone="light" accent="navy">
            <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                {{ __('app.dashboard.plan_label') }}
            </p>
            <p class="mt-1 text-xl font-bold text-trust-navy">{{ __('app.plans.'.$tenant->plan) }}</p>
        </x-card>

        <x-card tone="light" :accent="$rate ? 'green' : 'due-soon'">
            <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                {{ __('app.dashboard.exchange_rate') }}
            </p>
            <p class="mt-1 text-xl font-bold text-trust-navy" dir="ltr">
                {{ $rate ? number_format($rate) : __('app.dashboard.exchange_rate_unset') }}
            </p>
        </x-card>

        <x-card tone="light" :accent="$tenant->wa_phone_number ? 'green' : 'due-soon'">
            <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                {{ __('app.dashboard.whatsapp') }}
            </p>
            <p class="mt-1 text-xl font-bold text-trust-navy" dir="ltr">
                {{ $tenant->wa_phone_number ?: __('app.dashboard.whatsapp_unset') }}
            </p>
        </x-card>
    </div>

    <x-card tone="light" class="mt-8">
        <h2 class="text-lg font-semibold text-trust-navy">{{ __('app.dashboard.coming') }}</h2>
        <p class="mt-2 leading-relaxed text-warm-charcoal/80">{{ __('app.dashboard.coming_lede') }}</p>

        <ul class="mt-5 space-y-2.5">
            @foreach (__('app.dashboard.modules') as $module)
                <li class="flex items-start gap-2.5">
                    <span aria-hidden="true" class="mt-1.5 block size-2 shrink-0 rounded-full bg-warm-charcoal/25"></span>
                    <span class="text-warm-charcoal/90">{{ $module }}</span>
                </li>
            @endforeach
        </ul>
    </x-card>
</x-layouts.app>
