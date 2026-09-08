@php
    $canManage = auth()->user()->can('manage-payroll');
    $currency = $invoice->currency->value;
@endphp

<x-layouts.app :title="$invoice->label()">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl" dir="ltr">
                {{ $invoice->reference ?? __('app.invoices.unnumbered') }}
            </h1>
            @if ($invoice->isVoid())
                <p class="mt-1 text-sm font-semibold text-status-overdue">
                    {{ __('app.invoices.status_void') }} &middot; {{ $invoice->void_reason }}
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @unless ($invoice->isDraft() || $invoice->isVoid())
                <x-status-badge :status="$invoice->statusKey()">
                    {{ $invoice->isPaid()
                        ? __('app.invoices.status_paid')
                        : ($invoice->isOverdue() ? __('app.invoices.status_overdue') : __('app.invoices.status_issued')) }}
                </x-status-badge>
            @endunless
            <x-button :href="route('app.invoices.index')" variant="ghost" size="md">{{ __('app.invoices.back') }}</x-button>
        </div>
    </div>

    @if (session('status'))
        <x-card tone="light" accent="green" class="mt-6" role="status">
            <p class="font-medium text-warm-charcoal">{{ session('status') }}</p>
        </x-card>
    @endif

    @error('issue')
        <x-card tone="light" accent="overdue" class="mt-6">
            <p class="font-medium text-warm-charcoal">{{ $message }}</p>
        </x-card>
    @enderror

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        {{-- The document itself. --}}
        <x-card tone="light" :padded="false" class="overflow-hidden lg:col-span-2">
            <div class="border-b border-warm-charcoal/12 bg-warm-offwhite px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                    {{ __('app.invoices.bill_to') }}
                </p>
                <p class="mt-1 text-lg font-semibold text-trust-navy">{{ $invoice->customer->name }}</p>
                @if ($invoice->customer->vat_number)
                    <p class="text-sm text-warm-charcoal/80">
                        {{ __('app.invoices.vat_number') }}
                        <span dir="ltr" class="tabular-nums">{{ $invoice->customer->vat_number }}</span>
                    </p>
                @endif
                @if ($invoice->customer->phone)
                    <p class="text-sm text-warm-charcoal/80" dir="ltr">{{ $invoice->customer->phone }}</p>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-warm-charcoal/12">
                        <tr>
                            <th class="px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.invoices.line_description') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-end font-semibold text-warm-charcoal">{{ __('app.invoices.quantity') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-end font-semibold text-warm-charcoal">{{ __('app.invoices.unit_price') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-end font-semibold text-warm-charcoal">{{ __('app.invoices.line_total') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-end font-semibold text-warm-charcoal">{{ __('app.invoices.vat') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invoice->lines as $line)
                            <tr class="border-b border-warm-charcoal/8 last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium text-warm-charcoal">{{ $line->description }}</span>
                                    @if ($line->treatment->value !== 'standard')
                                        <span class="block text-xs text-warm-charcoal/70">{{ __('app.invoices.'.$line->treatment->value) }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums" dir="ltr">{{ $line->quantity() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums" dir="ltr">{{ $line->unitPrice()->format() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums" dir="ltr">{{ $line->net()->format() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums" dir="ltr">{{ $line->vat()->format() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-4 text-warm-charcoal/80">{{ __('app.invoices.no_lines') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <dl class="space-y-2 border-t border-warm-charcoal/12 px-5 py-4">
                @foreach ([['subtotal', $invoice->subtotal()], ['vat', $invoice->vat()], ['grand_total', $invoice->total()]] as [$key, $amount])
                    <div class="flex items-baseline justify-between gap-3 @if ($key === 'grand_total') border-t border-warm-charcoal/15 pt-2 text-lg font-bold text-trust-navy @else text-warm-charcoal @endif">
                        <dt>{{ __('app.invoices.'.$key) }}</dt>
                        <dd class="tabular-nums" dir="ltr">{{ $amount->format() }} {{ $currency }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($invoice->notes)
                <p class="border-t border-warm-charcoal/12 px-5 py-4 leading-relaxed text-warm-charcoal/80">{{ $invoice->notes }}</p>
            @endif
        </x-card>

        <div class="space-y-6">
            <x-card tone="light">
                <dl class="space-y-3">
                    @foreach ([
                        ['issued_on', $invoice->issued_on?->toDateString() ?? '—'],
                        ['due_on', $invoice->due_on?->toDateString() ?? '—'],
                        ['currency', $currency],
                    ] as [$key, $value])
                        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-warm-charcoal/8 pb-3 last:border-0 last:pb-0">
                            <dt class="font-medium text-warm-charcoal">{{ __('app.invoices.'.$key) }}</dt>
                            <dd class="tabular-nums text-warm-charcoal/80" dir="ltr">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                {{-- What this invoice contributed to the VAT return. The link
                     between the two modules, made visible. --}}
                @unless ($invoice->isDraft() || $invoice->isVoid())
                    <div class="mt-5 rounded-lg bg-compliance-green/10 p-4">
                        <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                            {{ __('app.invoices.declared') }}
                        </p>
                        <p class="mt-1 font-bold text-trust-navy" dir="ltr">
                            {{ $invoice->vatLbp()->format() }} LBP
                        </p>
                    </div>
                @endunless
            </x-card>

            {{-- Actions belong to invoices only: a credit note is neither paid nor
                 voided, and this view is shared between the two documents. --}}
            @if ($canManage && $invoice->document_type === 'invoice' && ! $invoice->isDraft() && ! $invoice->isVoid())
                <x-card tone="light">
                    @unless ($invoice->isPaid())
                        <form method="POST" action="{{ route('app.invoices.mark-paid', $invoice) }}">
                            @csrf
                            <x-button type="submit" variant="primary" size="md" class="w-full">
                                {{ __('app.invoices.mark_paid') }}
                            </x-button>
                        </form>
                    @endunless

                    {{-- Crediting is the correction that works after the VAT
                         period is filed; voiding only works before. --}}
                    <form method="POST" action="{{ route('app.invoices.credit.full', $invoice) }}" class="mt-4">
                        @csrf
                        <x-button
                            type="submit" variant="secondary" size="md" class="w-full"
                            onclick="return confirm(@js(__('app.credit_notes.confirm_full')))"
                        >{{ __('app.credit_notes.create_full') }}</x-button>
                        <p class="mt-2 text-xs leading-relaxed text-warm-charcoal/70">{{ __('app.credit_notes.explain') }}</p>
                    </form>

                    <a href="{{ route('app.invoices.credit.create', $invoice) }}"
                       class="mt-3 block rounded text-center text-sm font-medium text-trust-navy underline-offset-4 hover:underline">
                        {{ __('app.credit_notes.create_partial') }}
                    </a>

                    <form method="POST" action="{{ route('app.invoices.void', $invoice) }}" class="mt-4 space-y-3">
                        @csrf
                        <x-form.input name="void_reason" :label="__('app.invoices.void_reason')" />
                        <x-button
                            type="submit" variant="secondary" size="md" class="w-full"
                            onclick="return confirm(@js(__('app.invoices.void_confirm')))"
                        >{{ __('app.invoices.void') }}</x-button>
                    </form>
                </x-card>
            @endif

            @if (! $invoice->isDraft() && ! $invoice->isVoid() && $invoice->document_type === 'invoice' && $invoice->creditNotes->isNotEmpty())
                <x-card tone="light">
                    <h2 class="font-semibold text-trust-navy">{{ __('app.credit_notes.heading') }}</h2>
                    <ul class="mt-3 space-y-2">
                        @foreach ($invoice->creditNotes as $note)
                            <li class="flex flex-wrap items-baseline justify-between gap-2">
                                <a href="{{ route('app.credit-notes.show', $note) }}"
                                   class="rounded font-medium text-trust-navy underline-offset-4 hover:underline" dir="ltr">
                                    {{ $note->reference }}
                                </a>
                                <span class="tabular-nums text-warm-charcoal/80" dir="ltr">
                                    -{{ $note->total()->format() }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <dl class="mt-4 space-y-2 border-t border-warm-charcoal/12 pt-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-warm-charcoal">{{ __('app.credit_notes.creditable') }}</dt>
                            <dd class="tabular-nums font-semibold text-trust-navy" dir="ltr">
                                {{ \App\Support\Money::of($invoice->creditableMinor(), $invoice->currency)->format() }}
                            </dd>
                        </div>
                    </dl>
                </x-card>
            @endif

            @if ($invoice->document_type === 'credit_note' && $invoice->creditedInvoice)
                <x-card tone="light">
                    <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                        {{ __('app.credit_notes.against') }}
                    </p>
                    <a href="{{ route('app.invoices.show', $invoice->creditedInvoice) }}"
                       class="mt-1 block rounded font-bold text-trust-navy underline-offset-4 hover:underline" dir="ltr">
                        {{ $invoice->creditedInvoice->reference }}
                    </a>
                </x-card>
            @endif

            @unless ($invoice->isDraft())
                <p class="text-sm leading-relaxed text-warm-charcoal/70">{{ __('app.invoices.issued_note') }}</p>
            @endunless
        </div>
    </div>
</x-layouts.app>
