<x-layouts.app :title="__('app.revaluation.heading')">
    <div class="mx-auto max-w-5xl">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">{{ __('app.revaluation.heading') }}</h1>
        <p class="mt-3 max-w-prose leading-relaxed text-warm-charcoal/70">{{ __('app.revaluation.intro') }}</p>

        @if (! $today || ! $today->hasBoth())
            <x-card tone="light" accent="due-soon" class="mt-6">
                <p class="font-semibold text-warm-charcoal">{{ __('app.revaluation.no_rates') }}</p>
                <p class="mt-2 text-sm leading-relaxed text-warm-charcoal/70">{{ __('app.revaluation.no_rates_detail') }}</p>
            </x-card>
        @endif

        {{-- The books-versus-filing gap, as a number rather than a feeling. --}}
        <x-card tone="light" class="mt-6">
            <h2 class="font-semibold text-trust-navy">{{ __('app.revaluation.outstanding_heading') }}</h2>

            <dl class="mt-4 grid gap-5 sm:grid-cols-3">
                <div>
                    <dt class="text-sm text-warm-charcoal/70">{{ __('app.revaluation.outstanding_usd') }}</dt>
                    <dd class="mt-0.5 text-xl font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                        {{ $totalUsd->format() }} USD
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-warm-charcoal/70">{{ __('app.revaluation.at_market') }}</dt>
                    <dd class="mt-0.5 text-xl font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                        {{ $marketRate ? $totalUsd->convertTo(\App\Support\Currency::LBP, $marketRate)->format() : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-warm-charcoal/70">{{ __('app.revaluation.at_mof') }}</dt>
                    <dd class="mt-0.5 text-xl font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                        {{ $mofRate ? $totalUsd->convertTo(\App\Support\Currency::LBP, $mofRate)->format() : '—' }}
                    </dd>
                </div>
            </dl>

            <p class="mt-5 border-s-4 border-trust-navy ps-4 text-sm leading-relaxed text-warm-charcoal/70">
                {{ __('app.revaluation.explain') }}
            </p>
        </x-card>

        {{-- Per invoice: what has moved since it was issued. --}}
        <x-card tone="light" :padded="false" class="mt-6 overflow-hidden">
            <div class="border-b border-warm-charcoal/12 bg-warm-offwhite px-5 py-4">
                <h2 class="font-semibold text-trust-navy">{{ __('app.revaluation.invoices_heading') }}</h2>
            </div>

            @if ($rows->isEmpty())
                <p class="px-5 py-6 text-warm-charcoal/70">{{ __('app.revaluation.no_invoices') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wider text-warm-charcoal/70">
                            <tr class="border-b border-warm-charcoal/12">
                                <th class="px-5 py-3 text-start font-semibold">{{ __('app.revaluation.invoice') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">USD</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.revaluation.at_issue') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.revaluation.at_market') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.revaluation.movement') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $movement = $row['at_issue'] && $row['at_market']
                                        ? $row['at_market']->minus($row['at_issue'])
                                        : null;
                                @endphp
                                <tr class="border-b border-warm-charcoal/8 last:border-0">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('app.invoices.show', $row['invoice']) }}"
                                           class="rounded font-medium text-trust-navy underline-offset-4 hover:underline">
                                            {{ $row['invoice']->reference }}
                                        </a>
                                        <span class="block text-xs text-warm-charcoal/70">{{ $row['invoice']->customer->name }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-end tabular-nums" dir="ltr">{{ $row['total']->format() }}</td>
                                    <td class="px-5 py-3 text-end tabular-nums" dir="ltr">
                                        {{ $row['at_issue']?->format() ?? '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-end tabular-nums" dir="ltr">
                                        {{ $row['at_market']?->format() ?? '—' }}
                                    </td>
                                    <td @class([
                                        'px-5 py-3 text-end tabular-nums font-medium',
                                        'text-compliance-green' => $movement && $movement->minorUnits > 0,
                                        'text-status-overdue' => $movement && $movement->minorUnits < 0,
                                        'text-warm-charcoal/70' => ! $movement || $movement->isZero(),
                                    ]) dir="ltr">
                                        {{ $movement ? ($movement->minorUnits > 0 ? '+' : '').$movement->format() : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="border-t border-warm-charcoal/12 px-5 py-4 text-xs leading-relaxed text-warm-charcoal/70">
                    {{ __('app.revaluation.unrealized_note') }}
                </p>
            @endif
        </x-card>

        {{-- Rate history, so the gap is visible as a trend and not one day. --}}
        <x-card tone="light" :padded="false" class="mt-6 overflow-hidden">
            <div class="border-b border-warm-charcoal/12 bg-warm-offwhite px-5 py-4">
                <h2 class="font-semibold text-trust-navy">{{ __('app.revaluation.history_heading') }}</h2>
            </div>

            @if ($history->isEmpty())
                <p class="px-5 py-6 text-warm-charcoal/70">{{ __('app.rates.none_recorded') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wider text-warm-charcoal/70">
                            <tr class="border-b border-warm-charcoal/12">
                                <th class="px-5 py-3 text-start font-semibold">{{ __('app.rates.date') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.rates.basis_market') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.rates.basis_mof') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.rates.spread') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $rate)
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
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-layouts.app>
