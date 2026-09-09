@php $canManage = auth()->user()->can('manage-payroll'); @endphp

<x-layouts.app :title="__('app.invoices.heading')">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
            {{ __('app.invoices.heading') }}
        </h1>

        @if ($canManage)
            <x-button :href="route('app.invoices.create')" variant="cta" size="md">
                {{ __('app.invoices.new') }}
            </x-button>
        @endif
    </div>

    @if (session('status'))
        <x-card tone="light" accent="green" class="mt-6" role="status">
            <p class="font-medium text-warm-charcoal">{{ session('status') }}</p>
        </x-card>
    @endif

    @if ($invoices->isEmpty())
        <x-card tone="light" accent="navy" class="mt-8">
            <p class="leading-relaxed text-warm-charcoal/80">{{ __('app.invoices.none') }}</p>
        </x-card>
    @else
        <x-card tone="light" :padded="false" class="mt-8 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-warm-charcoal/12 bg-warm-offwhite">
                        <tr>
                            <th class="whitespace-nowrap px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.invoices.number') }}</th>
                            <th class="px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.invoices.customer') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.invoices.issued_on') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-end font-semibold text-warm-charcoal">{{ __('app.invoices.total') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.invoices.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr class="border-b border-warm-charcoal/8 last:border-0">
                                <td class="whitespace-nowrap px-5 py-3">
                                    <a href="{{ route('app.invoices.show', $invoice) }}"
                                       class="rounded font-medium text-trust-navy underline-offset-4 hover:underline"
                                       dir="ltr">{{ $invoice->reference ?? '—' }}</a>
                                </td>
                                <td class="px-5 py-3 text-warm-charcoal">{{ $invoice->customer->name }}</td>
                                <td class="whitespace-nowrap px-5 py-3 tabular-nums text-warm-charcoal/80" dir="ltr">
                                    {{ $invoice->issued_on?->toDateString() ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-end font-semibold tabular-nums" dir="ltr">
                                    {{ $invoice->total()->format() }}
                                    <span class="font-normal text-warm-charcoal/70">{{ $invoice->currency->value }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    @if ($invoice->isDraft())
                                        <span class="rounded-full bg-warm-charcoal/10 px-3 py-1 text-sm font-semibold text-warm-charcoal">
                                            {{ __('app.invoices.status_draft') }}
                                        </span>
                                    @elseif ($invoice->isVoid())
                                        <span class="rounded-full bg-warm-charcoal/10 px-3 py-1 text-sm font-semibold text-warm-charcoal/70 line-through">
                                            {{ __('app.invoices.status_void') }}
                                        </span>
                                    @else
                                        <x-status-badge :status="$invoice->statusKey()">
                                            {{ $invoice->statusLabel() }}
                                        </x-status-badge>
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
