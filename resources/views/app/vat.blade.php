@php
    $canManage = auth()->user()->can('manage-payroll');
    $pct = fn (int $bps) => rtrim(rtrim(number_format($bps / 100, 2), '0'), '.').'%';
@endphp

<x-layouts.app :title="__('app.vat.heading')">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
            {{ __('app.vat.heading') }}
        </h1>

        @if ($return)
            <div class="flex flex-wrap items-center gap-3">
                <span class="font-mono text-lg font-semibold text-warm-charcoal/80" dir="ltr">
                    {{ $return->periodLabel() }}
                </span>
                {{-- The same status vocabulary as the marketing site's
                     compliance section, from the same component. --}}
                <x-status-badge :status="$return->statusKey()">
                    @if ($return->isFinalised())
                        {{ __('app.vat.status_filed') }}
                    @elseif ($return->isOverdue())
                        {{ __('app.vat.days_over', ['count' => abs($return->daysUntilDue())]) }}
                    @else
                        {{ __('app.vat.days_left', ['count' => $return->daysUntilDue()]) }}
                    @endif
                </x-status-badge>
            </div>
        @endif
    </div>

    @if (session('status'))
        <x-card tone="light" accent="green" class="mt-6" role="status">
            <p class="font-medium text-warm-charcoal">{{ session('status') }}</p>
        </x-card>
    @endif

    @error('file')
        <x-card tone="light" accent="overdue" class="mt-6">
            <p class="font-medium text-warm-charcoal">{{ $message }}</p>
        </x-card>
    @enderror

    @if (! $schedule)
        {{-- Honest empty state: we cannot compute, so we say so rather than
             rendering a return full of zeros that looks filed-ready. --}}
        <x-card tone="light" accent="overdue" class="mt-8">
            <h2 class="text-lg font-semibold text-trust-navy">{{ __('app.vat.no_schedule_heading') }}</h2>
            <p class="mt-2 max-w-2xl leading-relaxed text-warm-charcoal/80">{{ __('app.vat.no_schedule_body') }}</p>
            <pre dir="ltr" class="mt-4 overflow-x-auto rounded-lg bg-warm-charcoal/5 p-3 text-start text-xs text-warm-charcoal/80"><code>{{ __('app.vat.no_schedule_hint') }}</code></pre>
        </x-card>
    @elseif (! $return)
        <x-card tone="light" accent="navy" class="mt-8">
            <h2 class="text-lg font-semibold text-trust-navy">{{ __('app.vat.no_returns_heading') }}</h2>
            <p class="mt-2 max-w-2xl leading-relaxed text-warm-charcoal/80">{{ __('app.vat.no_returns_body') }}</p>
            <pre dir="ltr" class="mt-4 overflow-x-auto rounded-lg bg-warm-charcoal/5 p-3 text-start text-xs text-warm-charcoal/80"><code>{{ __('app.vat.no_returns_hint') }}</code></pre>
        </x-card>
    @endif

    @if ($schedule && $canManage)
        <x-card tone="light" class="mt-8">
            <h2 class="text-lg font-semibold text-trust-navy">{{ __('app.vat.draft_run') }}</h2>

            <form method="POST" action="{{ route('app.vat.draft') }}" class="mt-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <x-form.input
                        name="period_year" type="number" :label="__('app.vat.year')"
                        :value="old('period_year', $defaultYear)" min="2000" max="2100" dir="ltr" required
                    />
                    <x-form.input
                        name="period_quarter" type="number" :label="__('app.vat.quarter')"
                        :value="old('period_quarter', $defaultQuarter)" min="1" max="4" dir="ltr" required
                    />
                    <x-button type="submit" variant="primary" size="md" class="w-full">
                        {{ __('app.vat.draft_submit') }}
                    </x-button>
                </div>
            </form>
        </x-card>
    @endif

    @if ($schedule && $return)
        @unless ($ratesVerified)
            <x-card tone="light" accent="due-soon" class="mt-6">
                <h2 class="font-semibold text-trust-navy">{{ __('app.vat.unverified_heading') }}</h2>
                <p class="mt-1.5 max-w-2xl leading-relaxed text-warm-charcoal/80">{{ __('app.vat.unverified_body') }}</p>
            </x-card>
        @endunless

        {{-- The bottom line first. --}}
        <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['taxable_sales', $return->taxable_sales_lbp, 'navy'],
                ['output_vat', $return->output_vat_lbp, 'navy'],
                ['input_vat', $return->input_vat_lbp, 'green'],
                [$return->isInCredit() ? 'carried_forward' : 'payable',
                 $return->isInCredit() ? $return->credit_carried_forward_lbp : $return->payable_lbp,
                 $return->isInCredit() ? 'green' : 'gold'],
            ] as [$key, $amount, $accent])
                <x-card tone="light" :accent="$accent">
                    <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                        {{ __('app.vat.'.$key) }}
                    </p>
                    <p class="mt-1 text-xl font-bold text-trust-navy" dir="ltr">{{ number_format($amount) }}</p>
                    <p class="text-xs text-warm-charcoal/70" dir="ltr">LBP</p>
                </x-card>
            @endforeach
        </div>

        @if ($return->isInCredit())
            <p class="mt-4 max-w-2xl text-sm leading-relaxed text-warm-charcoal/80">
                {{ __('app.vat.in_credit_note') }}
            </p>
        @endif

        <div class="mt-8 grid gap-5 lg:grid-cols-3">
            <x-card tone="light" class="lg:col-span-1">
                <dl class="space-y-3">
                    @foreach ([
                        ['period', $return->start()->toDateString().' — '.$return->end()->toDateString()],
                        ['due', $return->due_on->toDateString()],
                        ['rate', $pct($return->standard_rate_bps)],
                        ['exempt_sales', number_format($return->exempt_sales_lbp).' LBP'],
                        ['purchases', number_format($return->purchases_lbp).' LBP'],
                    ] as [$key, $value])
                        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-warm-charcoal/8 pb-3 last:border-0 last:pb-0">
                            <dt class="font-medium text-warm-charcoal">{{ __('app.vat.'.$key) }}</dt>
                            <dd class="tabular-nums text-warm-charcoal/80" dir="ltr">{{ $value }}</dd>
                        </div>
                    @endforeach

                    @if ($return->credit_brought_forward_lbp > 0)
                        <div class="flex flex-wrap items-baseline justify-between gap-2 pt-1">
                            <dt class="font-medium text-warm-charcoal">{{ __('app.vat.brought_forward') }}</dt>
                            <dd class="tabular-nums text-warm-charcoal/80" dir="ltr">
                                {{ number_format($return->credit_brought_forward_lbp) }} LBP
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($canManage && ! $return->isFinalised())
                    <form method="POST" action="{{ route('app.vat.file') }}" class="mt-6">
                        @csrf
                        <x-button type="submit" variant="cta" size="md" class="w-full">
                            {{ __('app.vat.file_submit') }}
                        </x-button>
                    </form>
                @endif
            </x-card>

            <x-card tone="light" :padded="false" class="overflow-hidden lg:col-span-2">
                <h2 class="border-b border-warm-charcoal/12 bg-warm-offwhite px-5 py-3 font-semibold text-trust-navy">
                    {{ __('app.vat.transactions') }}
                </h2>

                @if ($transactions->isEmpty())
                    <p class="p-5 text-warm-charcoal/80">{{ __('app.vat.no_transactions') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-warm-charcoal/12">
                                <tr>
                                    <th class="whitespace-nowrap px-4 py-2.5 text-start font-semibold text-warm-charcoal">{{ __('app.vat.date') }}</th>
                                    <th class="px-4 py-2.5 text-start font-semibold text-warm-charcoal">{{ __('app.vat.description') }}</th>
                                    <th class="whitespace-nowrap px-4 py-2.5 text-end font-semibold text-warm-charcoal">{{ __('app.vat.net') }}</th>
                                    <th class="whitespace-nowrap px-4 py-2.5 text-end font-semibold text-warm-charcoal">{{ __('app.vat.vat') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transactions as $t)
                                    <tr class="border-b border-warm-charcoal/8 last:border-0">
                                        <td class="whitespace-nowrap px-4 py-2.5 tabular-nums" dir="ltr">{{ $t->transacted_on->toDateString() }}</td>
                                        <td class="px-4 py-2.5">
                                            <span class="font-medium text-warm-charcoal">{{ $t->description }}</span>
                                            <span class="block text-xs text-warm-charcoal/70">
                                                {{ __('app.vat.'.$t->direction) }}
                                                &middot; {{ __('app.vat.'.$t->treatment->value) }}
                                                @if ($t->direction === 'purchase' && ! $t->is_recoverable)
                                                    &middot; {{ __('app.vat.not_recoverable') }}
                                                @endif
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-end tabular-nums" dir="ltr">{{ number_format($t->net_lbp) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-end tabular-nums" dir="ltr">{{ number_format($t->vat_lbp) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    @endif
</x-layouts.app>
