@php
    $currency = $invoice->currency->value;

    // Repopulate from old() after a validation failure, else pre-fill with the
    // invoice's own lines so a full credit is one click and a partial one is
    // an edit rather than re-typing the document.
    $lines = old('lines') ?: $invoice->lines->map(fn ($l) => [
        'description' => $l->description,
        'quantity' => $l->quantity(),
        'unit_price' => $l->unitPrice()->format(),
        'treatment' => $l->treatment->value,
    ])->all();

    $originals = $invoice->lines->map(
        fn ($l) => $l->quantity().' × '.$l->unitPrice()->format().' = '.$l->net()->format().' '.$currency
    )->all();

    $treatments = [
        'standard' => __('app.invoices.standard'),
        'zero_rated' => __('app.invoices.zero_rated'),
        'exempt' => __('app.invoices.exempt'),
    ];
@endphp

<x-layouts.app :title="__('app.credit_notes.new')">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
            {{ __('app.credit_notes.new') }}
        </h1>
        <x-button :href="route('app.invoices.show', $invoice)" variant="ghost" size="md">
            {{ $invoice->reference }}
        </x-button>
    </div>

    <p class="mt-3 max-w-3xl leading-relaxed text-warm-charcoal/80">{{ __('app.credit_notes.explain') }}</p>

    @error('issue')
        <x-card tone="light" accent="overdue" class="mt-6">
            <p class="font-medium text-warm-charcoal">{{ $message }}</p>
        </x-card>
    @enderror

    {{-- The ceiling, stated before the form rather than only enforced after
         submitting it. --}}
    <div class="mt-8 grid gap-5 sm:grid-cols-3">
        @foreach ([
            ['against', $invoice->total(), 'navy'],
            ['credited', \App\Support\Money::of($invoice->creditedMinor(), $invoice->currency), 'navy'],
            ['creditable', \App\Support\Money::of($invoice->creditableMinor(), $invoice->currency), 'gold'],
        ] as [$key, $amount, $accent])
            <x-card tone="light" :accent="$accent">
                <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                    {{ __('app.credit_notes.'.$key) }}
                </p>
                <p class="mt-1 text-xl font-bold text-trust-navy" dir="ltr">{{ $amount->format() }}</p>
                <p class="text-xs text-warm-charcoal/70" dir="ltr">{{ $currency }}</p>
            </x-card>
        @endforeach
    </div>

    <form method="POST" action="{{ route('app.invoices.credit', $invoice) }}" class="mt-8" novalidate>
        @csrf

        <x-card tone="light">
            <h2 class="text-lg font-semibold text-trust-navy">{{ __('app.invoices.lines') }}</h2>
            <p class="mt-1 text-sm text-warm-charcoal/70">{{ __('app.credit_notes.lines_hint') }}</p>

            @error('lines')
                <p class="mt-2 text-sm font-medium text-status-overdue">{{ $message }}</p>
            @enderror

            <div class="mt-4">
                <x-document-lines :lines="$lines" :treatments="$treatments" :originals="$originals" />
            </div>
        </x-card>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <x-button type="submit" variant="cta" size="lg">{{ __('app.credit_notes.issue') }}</x-button>
            <x-button :href="route('app.invoices.show', $invoice)" variant="ghost" size="lg">
                {{ __('app.invoices.back') }}
            </x-button>
        </div>

        <p class="mt-3 text-sm text-warm-charcoal/70">{{ __('app.credit_notes.issue_hint') }}</p>
    </form>
</x-layouts.app>
