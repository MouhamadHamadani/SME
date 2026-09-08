<x-layouts.app :title="__('app.payroll.heading')">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
            {{ __('app.payroll.heading') }}
        </h1>

        @if ($run)
            <div class="flex items-center gap-3">
                <span class="font-mono text-lg font-semibold text-warm-charcoal/80" dir="ltr">
                    {{ $run->periodLabel() }}
                </span>
                <x-status-badge :status="$run->isFinalised() ? 'filed' : 'due-soon'">
                    {{ $run->isFinalised() ? __('app.payroll.status_finalised') : __('app.payroll.status_draft') }}
                </x-status-badge>
            </div>
        @endif
    </div>

    @if (! $schedule)
        {{-- The honest empty state: we cannot compute, so we say so rather
             than rendering a payroll of zeros that looks like it worked. --}}
        <x-card tone="light" accent="overdue" class="mt-8">
            <h2 class="text-lg font-semibold text-trust-navy">{{ __('app.payroll.no_schedule_heading') }}</h2>
            <p class="mt-2 max-w-2xl leading-relaxed text-warm-charcoal/80">
                {{ __('app.payroll.no_schedule_body') }}
            </p>
            <pre dir="ltr" class="mt-4 overflow-x-auto rounded-lg bg-warm-charcoal/5 p-3 text-start text-xs text-warm-charcoal/80"><code>{{ __('app.payroll.no_schedule_hint') }}</code></pre>
        </x-card>
    @elseif (! $run)
        <x-card tone="light" accent="navy" class="mt-8">
            <h2 class="text-lg font-semibold text-trust-navy">{{ __('app.payroll.no_runs_heading') }}</h2>
            <p class="mt-2 max-w-2xl leading-relaxed text-warm-charcoal/80">
                {{ __('app.payroll.no_runs_body') }}
            </p>
            <pre dir="ltr" class="mt-4 overflow-x-auto rounded-lg bg-warm-charcoal/5 p-3 text-start text-xs text-warm-charcoal/80"><code>{{ __('app.payroll.no_runs_hint') }}</code></pre>
        </x-card>
    @else
        @unless ($ratesVerified)
            <x-card tone="light" accent="due-soon" class="mt-6">
                <h2 class="font-semibold text-trust-navy">{{ __('app.payroll.unverified_heading') }}</h2>
                <p class="mt-1.5 max-w-2xl leading-relaxed text-warm-charcoal/80">
                    {{ __('app.payroll.unverified_body') }}
                </p>
            </x-card>
        @endunless

        {{-- Totals first: what the business actually needs to know. --}}
        <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['total_gross', $run->totalGross(), 'navy'],
                ['total_employee', $run->totalEmployeeDeductions(), 'navy'],
                ['total_employer', $run->totalEmployerContributions(), 'green'],
                ['total_cost', $run->totalEmployerCost(), 'gold'],
            ] as [$key, $amount, $accent])
                <x-card tone="light" :accent="$accent">
                    <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">
                        {{ __('app.payroll.'.$key) }}
                    </p>
                    <p class="mt-1 text-xl font-bold text-trust-navy" dir="ltr">{{ $amount->format() }}</p>
                    <p class="text-xs text-warm-charcoal/70" dir="ltr">LBP</p>
                </x-card>
            @endforeach
        </div>

        {{-- Wide table: scrolls inside its own box so the page never scrolls
             sideways, in either direction. --}}
        <x-card tone="light" :padded="false" class="mt-8 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-warm-charcoal/12 bg-warm-offwhite">
                        <tr>
                            <th class="px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.payroll.employee') }}</th>
                            @foreach (['gross', 'employee_nssf', 'employer_nssf', 'net'] as $col)
                                <th class="whitespace-nowrap px-5 py-3 text-end font-semibold text-warm-charcoal">
                                    {{ __('app.payroll.'.$col) }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($run->lines as $line)
                            <tr class="border-b border-warm-charcoal/8 last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium text-warm-charcoal">{{ $line->employee_name }}</span>
                                    @unless ($line->is_nssf_registered)
                                        <span class="ms-2 text-xs text-warm-charcoal/70">({{ __('app.payroll.undeclared') }})</span>
                                    @endunless
                                </td>
                                {{-- dir="ltr" on every figure: numbers read
                                     left-to-right even inside an RTL page. --}}
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums" dir="ltr">{{ $line->gross()->format() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums" dir="ltr">{{ $line->employeeDeductions()->format() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums" dir="ltr">{{ $line->employerContributions()->format() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-end font-semibold tabular-nums" dir="ltr">{{ $line->net()->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        {{-- The rules this run used — snapshotted, so this is what it actually
             computed with, not what the schedule says today. --}}
        <x-card tone="light" class="mt-8">
            <h2 class="text-lg font-semibold text-trust-navy">{{ __('app.payroll.breakdown') }}</h2>

            @php
                // A combined "11%" hides who pays what. The split is the point:
                // only sickness and maternity is shared with the employee.
                $pct = fn (int $bps) => rtrim(rtrim(number_format($bps / 100, 2), '0'), '.').'%';
            @endphp

            <dl class="mt-4 space-y-3">
                @foreach ([
                    ['sickness', $pct($run->sickness_employer_bps).' + '.$pct($run->sickness_employee_bps), $run->sickness_ceiling_lbp],
                    ['family', $pct($run->family_employer_bps), $run->family_ceiling_lbp],
                    ['eosi', $pct($run->eosi_employer_bps), null],
                ] as [$branch, $rate, $ceiling])
                    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-warm-charcoal/8 pb-3 last:border-0 last:pb-0">
                        <dt class="font-medium text-warm-charcoal">
                            {{ __('app.payroll.'.$branch) }}
                            @if ($branch === 'sickness')
                                <span class="text-sm font-normal text-warm-charcoal/70">
                                    ({{ __('app.payroll.employer_nssf') }} + {{ __('app.payroll.employee_nssf') }})
                                </span>
                            @endif
                        </dt>
                        <dd class="text-warm-charcoal/80">
                            <span dir="ltr" class="tabular-nums">{{ $rate }}</span>
                            <span class="text-warm-charcoal/70">
                                @if ($ceiling)
                                    &middot; {{ __('app.payroll.ceiling') }} <span dir="ltr" class="tabular-nums">{{ number_format($ceiling) }}</span>
                                @else
                                    &middot; {{ __('app.payroll.uncapped') }}
                                @endif
                            </span>
                        </dd>
                    </div>
                @endforeach

                <div class="flex flex-wrap items-baseline justify-between gap-2 pt-1">
                    <dt class="font-medium text-warm-charcoal">{{ __('app.payroll.rate_applied') }}</dt>
                    <dd class="tabular-nums text-warm-charcoal/80" dir="ltr">
                        {{ number_format($run->lbp_per_usd) }} LBP / USD
                    </dd>
                </div>
            </dl>
        </x-card>
    @endif
</x-layouts.app>
