@php
    $bookingRate = $tenant->settings['exchange_rate'] ?? null;
@endphp

<x-layouts.app :title="__('app.rates.heading')">
    <div class="mx-auto max-w-4xl">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">{{ __('app.rates.heading') }}</h1>
        <p class="mt-3 max-w-prose leading-relaxed text-warm-charcoal/70">{{ __('app.rates.intro') }}</p>

        @if (session('status'))
            <x-card tone="light" accent="green" class="mt-6" role="status">
                <p class="font-medium text-warm-charcoal">{{ session('status') }}</p>
            </x-card>
        @endif

        {{-- Today's national pair. Read-only: these are national figures and a
             business editing them would be editing everyone's filing rate. --}}
        <x-card tone="light" class="mt-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="font-semibold text-trust-navy">{{ __('app.rates.national_heading') }}</h2>
                @if ($today)
                    <span class="text-sm tabular-nums text-warm-charcoal/70" dir="ltr">{{ $today->rate_date->toDateString() }}</span>
                @endif
            </div>

            @if (! $today)
                <p class="mt-3 text-warm-charcoal/70">{{ __('app.rates.none_recorded') }}</p>
            @else
                <dl class="mt-4 grid gap-5 sm:grid-cols-3">
                    <div>
                        <dt class="text-sm text-warm-charcoal/70">{{ __('app.rates.basis_market') }}</dt>
                        <dd class="mt-0.5 text-xl font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                            {{ $today->market_rate_lbp_per_usd ? number_format($today->market_rate_lbp_per_usd) : '—' }}
                        </dd>
                        <dd class="text-xs text-warm-charcoal/70">{{ __('app.rates.basis_market_use') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-warm-charcoal/70">{{ __('app.rates.basis_mof') }}</dt>
                        <dd class="mt-0.5 text-xl font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                            {{ $today->mof_rate_lbp_per_usd ? number_format($today->mof_rate_lbp_per_usd) : '—' }}
                        </dd>
                        <dd class="text-xs text-warm-charcoal/70">{{ __('app.rates.basis_mof_use') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-warm-charcoal/70">{{ __('app.rates.spread') }}</dt>
                        <dd class="mt-0.5 text-xl font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                            {{ $today->spreadBps() === null ? '—' : number_format($today->spreadBps() / 100, 2).'%' }}
                        </dd>
                        <dd class="text-xs text-warm-charcoal/70">
                            <x-status-badge :status="$today->isVerified() ? 'filed' : 'due-soon'">
                                {{ $today->isVerified() ? __('app.rates.verified') : __('app.rates.unverified') }}
                            </x-status-badge>
                        </dd>
                    </div>
                </dl>

                @unless ($today->isVerified())
                    <p class="mt-4 border-s-4 border-status-due-soon ps-4 text-sm leading-relaxed text-warm-charcoal">
                        {{ __('app.rates.unverified_warning') }}
                    </p>
                @endunless
            @endif

            <p class="mt-5 text-xs leading-relaxed text-warm-charcoal/70">{{ __('app.rates.national_readonly') }}</p>
        </x-card>

        {{-- The one rate a business genuinely owns. --}}
        <form method="POST" action="{{ route('app.settings.exchange-rates.update') }}" class="mt-6" novalidate>
            @csrf
            <x-card tone="light" class="space-y-5">
                <div>
                    <h2 class="font-semibold text-trust-navy">{{ __('app.rates.booking_heading') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-warm-charcoal/70">{{ __('app.rates.booking_intro') }}</p>
                </div>

                <x-form.input
                    name="booking_rate"
                    :label="__('app.rates.booking_rate')"
                    :hint="__('app.rates.booking_hint')"
                    :value="$bookingRate ? (int) $bookingRate : null"
                    inputmode="numeric" dir="ltr" required
                />

                @can('manage-settings')
                    <x-button type="submit" variant="cta" size="lg">{{ __('app.rates.save') }}</x-button>
                @else
                    <p class="text-sm text-warm-charcoal/70">{{ __('app.rates.owner_only') }}</p>
                @endcan
            </x-card>
        </form>

        {{-- History. Wide content scrolls in its own container. --}}
        <x-card tone="light" :padded="false" class="mt-6 overflow-hidden">
            <div class="border-b border-warm-charcoal/12 bg-warm-offwhite px-5 py-4">
                <h2 class="font-semibold text-trust-navy">{{ __('app.rates.history_heading') }}</h2>
            </div>

            @if ($rates->isEmpty())
                <p class="px-5 py-6 text-warm-charcoal/70">{{ __('app.rates.none_recorded') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-start text-xs uppercase tracking-wider text-warm-charcoal/70">
                            <tr class="border-b border-warm-charcoal/12">
                                <th class="px-5 py-3 text-start font-semibold">{{ __('app.rates.date') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.rates.basis_market') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.rates.basis_mof') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.rates.spread') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('app.rates.source') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rates as $rate)
                                <tr class="border-b border-warm-charcoal/8 last:border-0">
                                    <td class="px-5 py-3 tabular-nums" dir="ltr">{{ $rate->rate_date->toDateString() }}</td>
                                    <td class="px-5 py-3 text-end tabular-nums" dir="ltr">
                                        {{ $rate->market_rate_lbp_per_usd ? number_format($rate->market_rate_lbp_per_usd) : '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-end tabular-nums" dir="ltr">
                                        {{ $rate->mof_rate_lbp_per_usd ? number_format($rate->mof_rate_lbp_per_usd) : '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-end tabular-nums" dir="ltr">
                                        {{ $rate->spreadBps() === null ? '—' : number_format($rate->spreadBps() / 100, 2).'%' }}
                                    </td>
                                    <td class="px-5 py-3 text-warm-charcoal/70">{{ $rate->source ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <p class="mt-6">
            <a href="{{ route('app.revaluation') }}" class="rounded font-medium text-trust-navy underline-offset-4 hover:underline">
                {{ __('app.rates.see_revaluation') }}
            </a>
        </p>
    </div>
</x-layouts.app>
