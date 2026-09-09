@php
    $canManage = auth()->user()->can('manage-payroll');

    // Grouped by currency, because a single "total outstanding" across LBP and
    // USD is a number that means nothing. Money::plus() would throw rather than
    // produce it, which is the point.
    $totals = $invoices->groupBy(fn ($invoice) => $invoice->currency->value)
        ->map(fn ($group) => $group->reduce(
            fn ($carry, $invoice) => $carry ? $carry->plus($invoice->balance()) : $invoice->balance()
        ));
@endphp

<x-layouts.app :title="__('app.payments.outstanding_heading')">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
            {{ __('app.payments.outstanding_heading') }}
        </h1>

        @if ($overdue->isNotEmpty())
            <x-status-badge status="overdue">
                {{ __('app.payments.overdue_count', ['count' => $overdue->count()]) }}
            </x-status-badge>
        @endif
    </div>

    @if ($invoices->isEmpty())
        <x-card tone="light" accent="green" class="mt-8">
            <p class="leading-relaxed text-warm-charcoal/80">{{ __('app.payments.outstanding_none') }}</p>
        </x-card>
    @else
        <p class="mt-2 text-warm-charcoal/80">{{ __('app.payments.outstanding_intro') }}</p>

        <div class="mt-6 flex flex-wrap gap-4">
            @foreach ($totals as $code => $total)
                <x-card tone="light" accent="navy" class="min-w-56">
                    <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                        {{ __('app.payments.outstanding_total') }}
                    </p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-trust-navy" dir="ltr">
                        {{ $total->format() }} {{ $code }}
                    </p>
                </x-card>
            @endforeach
        </div>

        <x-card tone="light" :padded="false" class="mt-8 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-warm-charcoal/12 bg-warm-offwhite">
                        <tr>
                            @foreach (['invoices.number', 'invoices.customer', 'invoices.due_on'] as $key)
                                <th class="whitespace-nowrap px-5 py-3 text-start font-semibold text-warm-charcoal">
                                    {{ __('app.'.$key) }}
                                </th>
                            @endforeach
                            @foreach (['invoices.total', 'payments.paid_to_date', 'payments.balance'] as $key)
                                <th class="whitespace-nowrap px-5 py-3 text-end font-semibold text-warm-charcoal">
                                    {{ __('app.'.$key) }}
                                </th>
                            @endforeach
                            <th class="whitespace-nowrap px-5 py-3 text-start font-semibold text-warm-charcoal">
                                {{ __('app.invoices.status') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr class="border-b border-warm-charcoal/8 last:border-0">
                                <td class="whitespace-nowrap px-5 py-3">
                                    <a href="{{ route('app.invoices.show', $invoice) }}"
                                       class="rounded font-medium text-trust-navy underline-offset-4 hover:underline"
                                       dir="ltr">{{ $invoice->reference }}</a>
                                </td>
                                <td class="px-5 py-3 text-warm-charcoal">{{ $invoice->customer->name }}</td>
                                <td class="whitespace-nowrap px-5 py-3 tabular-nums text-warm-charcoal/80" dir="ltr">
                                    {{ $invoice->due_on?->toDateString() ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums text-warm-charcoal/80" dir="ltr">
                                    {{ $invoice->total()->format() }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums text-warm-charcoal/80" dir="ltr">
                                    {{ $invoice->amountPaid()->format() }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-end font-semibold tabular-nums" dir="ltr">
                                    {{ $invoice->balance()->format() }}
                                    <span class="font-normal text-warm-charcoal/70">{{ $invoice->currency->value }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    <x-status-badge :status="$invoice->statusKey()">
                                        {{ $invoice->statusLabel() }}
                                    </x-status-badge>
                                    @if ($canManage)
                                        <a href="{{ route('app.payments.create', $invoice) }}"
                                           class="ms-2 rounded text-sm font-medium text-trust-navy underline-offset-4 hover:underline">
                                            {{ __('app.payments.record') }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-layouts.app>
