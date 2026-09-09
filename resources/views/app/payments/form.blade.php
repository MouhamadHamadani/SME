@php
    $currency = $invoice->currency->value;
    $balance = $invoice->balance();
@endphp

<x-layouts.app :title="__('app.payments.record')">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
            {{ __('app.payments.record_heading', ['reference' => $invoice->label()]) }}
        </h1>

        <x-button :href="route('app.invoices.show', $invoice)" variant="ghost" size="md">
            {{ __('app.payments.back_to_invoice') }}
        </x-button>
    </div>

    {{-- The ceiling, stated BEFORE the form rather than only enforced on
         submit. Same discipline as the credit-note form: a limit somebody only
         meets by tripping over it is a worse limit. --}}
    <x-card tone="light" accent="navy" class="mt-6">
        <p class="font-semibold text-trust-navy">
            {{ __('app.payments.balance_before', ['amount' => $balance->format().' '.$currency]) }}
        </p>

        <dl class="mt-4 space-y-2 text-sm">
            @foreach ([
                ['invoiced', $invoice->total()],
                ['credited', \App\Support\Money::of($invoice->creditedMinor(), $invoice->currency)],
                ['paid_to_date', $invoice->amountPaid()],
            ] as [$key, $amount])
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-warm-charcoal">{{ __('app.payments.'.$key) }}</dt>
                    <dd class="tabular-nums text-warm-charcoal/80" dir="ltr">{{ $amount->format() }} {{ $currency }}</dd>
                </div>
            @endforeach
        </dl>
    </x-card>

    @error('amount')
        <x-card tone="light" accent="overdue" class="mt-6">
            <p class="font-medium text-warm-charcoal">{{ $message }}</p>
        </x-card>
    @enderror

    <form method="POST" action="{{ route('app.payments.store', $invoice) }}" class="mt-8 max-w-2xl space-y-6">
        @csrf

        <div class="grid gap-6 sm:grid-cols-2">
            <x-form.input
                name="amount"
                :label="__('app.payments.amount')"
                :hint="__('app.payments.amount_hint')"
                :value="$balance->format()"
                inputmode="decimal"
                dir="ltr"
                required
            />

            {{-- Defaulted to the invoice's currency, and a mismatch is refused
                 rather than converted. The field stays visible so the refusal
                 is a rule somebody can see rather than a surprise. --}}
            <x-form.select
                name="currency"
                :label="__('app.payments.currency')"
                :hint="__('app.payments.currency_hint')"
                :options="['LBP' => 'LBP', 'USD' => 'USD']"
                :selected="$currency"
                required
            />

            <x-form.select
                name="method"
                :label="__('app.payments.method')"
                :options="$methods"
                :selected="'cash'"
                required
            />

            {{-- Native date input: no picker library, and it localises itself. --}}
            <x-form.input
                name="received_on"
                type="date"
                :label="__('app.payments.received_on')"
                :hint="__('app.payments.received_on_hint')"
                :value="now()->toDateString()"
                :max="now()->toDateString()"
                required
            />
        </div>

        <x-form.input
            name="reference"
            :label="__('app.payments.reference')"
            :hint="__('app.payments.reference_hint')"
        />

        <x-form.field name="notes" :label="__('app.payments.notes')">
            <textarea
                id="notes" name="notes" rows="3"
                class="w-full rounded-lg border-2 border-warm-charcoal/20 bg-white px-4 py-3 text-base text-warm-charcoal transition-colors focus:border-trust-navy focus:outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
            >{{ old('notes') }}</textarea>
        </x-form.field>

        <x-button type="submit" variant="primary" size="md">{{ __('app.payments.submit') }}</x-button>
    </form>
</x-layouts.app>
