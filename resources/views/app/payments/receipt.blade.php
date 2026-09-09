@php
    $invoice = $payment->invoice;
    $currency = $payment->currency->value;
@endphp

{{--
    The receipt. Read-only, numbered, and the only page in the app whose
    purpose is to be handed to somebody else.

    HTML only, like the invoice view — see docs/INVOICING.md §8. No PDF and no
    print stylesheet: that gap covers both documents and is tracked once rather
    than half-closed here.
--}}
<x-layouts.app :title="__('app.payments.receipt_heading', ['reference' => $payment->receiptReference()])">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                {{ __('app.payments.receipt') }}
            </p>
            <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl" dir="ltr">
                {{ $payment->receiptReference() }}
            </h1>
        </div>

        <x-button :href="route('app.invoices.show', $invoice)" variant="ghost" size="md">
            {{ __('app.payments.back_to_invoice') }}
        </x-button>
    </div>

    <x-card tone="light" :padded="false" class="mt-8 max-w-2xl overflow-hidden">
        <div class="border-b border-warm-charcoal/12 bg-warm-offwhite px-5 py-4">
            <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                {{ __('app.payments.receipt_for') }}
            </p>
            <p class="mt-1 text-lg font-semibold text-trust-navy">{{ $invoice->customer->name }}</p>
        </div>

        <div class="border-b border-warm-charcoal/12 px-5 py-6 text-center">
            <p class="text-3xl font-bold tabular-nums text-trust-navy sm:text-4xl" dir="ltr">
                {{ $payment->amount()->format() }} {{ $currency }}
            </p>
            {{-- A USD receipt states what it was worth in lira at the market
                 rate the day the money arrived, and at which rate. Absent
                 entirely when no rate was recorded — an unknown rate is not
                 zero and not yesterday's. --}}
            @if ($currency !== 'LBP' && $payment->amountLbp())
                <p class="mt-1 text-sm tabular-nums text-warm-charcoal/70" dir="ltr">
                    {{ $payment->amountLbp()->format() }} LBP &middot;
                    {{ number_format((int) $payment->lbp_per_usd) }} LBP/USD
                </p>
            @endif
        </div>

        <dl class="space-y-3 px-5 py-4">
            @foreach ([
                ['method', $payment->method->label()],
                ['received_on', $payment->received_on->toDateString()],
                ['reference', $payment->reference ?: '—'],
                ['recorded_by', $payment->recordedBy?->name ?? __('app.payments.recorded_by_unknown')],
            ] as [$key, $value])
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-warm-charcoal/8 pb-3 last:border-0 last:pb-0">
                    <dt class="font-medium text-warm-charcoal">{{ __('app.payments.'.$key) }}</dt>
                    <dd class="text-warm-charcoal/80">{{ $value }}</dd>
                </div>
            @endforeach

            <div class="flex flex-wrap items-baseline justify-between gap-2 border-t border-warm-charcoal/12 pt-3">
                <dt class="font-medium text-warm-charcoal">{{ __('app.payments.receipt_against') }}</dt>
                <dd>
                    <a href="{{ route('app.invoices.show', $invoice) }}"
                       class="rounded font-semibold text-trust-navy underline-offset-4 hover:underline" dir="ltr">
                        {{ $invoice->reference }}
                    </a>
                </dd>
            </div>

            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <dt class="font-medium text-warm-charcoal">{{ __('app.payments.balance') }}</dt>
                <dd class="tabular-nums font-semibold text-trust-navy" dir="ltr">
                    {{ $invoice->balance()->format() }} {{ $invoice->currency->value }}
                </dd>
            </div>
        </dl>

        @if ($payment->notes)
            <p class="border-t border-warm-charcoal/12 px-5 py-4 leading-relaxed text-warm-charcoal/80">
                {{ $payment->notes }}
            </p>
        @endif
    </x-card>

    {{-- Said plainly: a receipt is not a tax invoice, and nobody should file
         one as though it were. --}}
    <p class="mt-4 max-w-2xl text-sm leading-relaxed text-warm-charcoal/70">
        {{ __('app.payments.receipt_note') }}
    </p>
</x-layouts.app>
