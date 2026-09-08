@php
    $inputs = $estimate->inputs;
    $qualifying = $inputs['qualifying_kinds'] ?? [];
    $excluded = $inputs['excluded_kinds'] ?? [];
@endphp

<x-layouts.app :title="__('app.eosi.heading')">
    <div class="mx-auto max-w-4xl">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wider text-warm-charcoal/70">{{ __('app.eosi.heading') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">{{ $employee->name }}</h1>
            </div>
            <x-button :href="route('app.employees.index')" variant="ghost" size="md">{{ __('app.employees.heading') }}</x-button>
        </div>

        @if (session('status'))
            <x-card tone="light" accent="green" class="mt-6" role="status">
                <p class="font-medium text-warm-charcoal">{{ session('status') }}</p>
            </x-card>
        @endif

        {{-- The estimate, and the working. An indemnity figure a person cannot
             audit is one they cannot defend to a departing employee. --}}
        <x-card tone="light" accent="navy" class="mt-6">
            <h2 class="font-semibold text-trust-navy">{{ __('app.eosi.estimate_heading') }}</h2>

            <p class="mt-3 text-3xl font-bold tabular-nums text-trust-navy" dir="ltr">
                {{ $estimate->accrued->format() }}
                <span class="text-lg font-semibold text-warm-charcoal/70">{{ $estimate->accrued->currency->value }}</span>
            </p>

            <dl class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm text-warm-charcoal/70">{{ __('app.eosi.service') }}</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                        {{ __('app.eosi.months', ['count' => $estimate->serviceMonths]) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-warm-charcoal/70">{{ __('app.eosi.coefficient') }}</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums text-warm-charcoal" dir="ltr">{{ $estimate->coefficientPercent() }}</dd>
                    {{-- Only worth saying when the two clocks differ, which is
                         exactly when someone would otherwise misread the band. --}}
                    @if ($estimate->enrolmentMonths !== $estimate->serviceMonths)
                        <dd class="text-xs text-warm-charcoal/70">
                            {{ __('app.eosi.from_enrolment', ['count' => $estimate->enrolmentMonths]) }}
                        </dd>
                    @endif
                </div>
                <div>
                    <dt class="text-sm text-warm-charcoal/70">{{ __('app.eosi.average_salary') }}</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums text-warm-charcoal" dir="ltr">{{ $estimate->averageSalary->format() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-warm-charcoal/70">{{ __('app.eosi.formula') }}</dt>
                    <dd class="mt-0.5 font-semibold text-warm-charcoal" dir="ltr">{{ $estimate->strategy }} {{ $estimate->formulaVersion }}</dd>
                </div>
            </dl>

            <p class="mt-6 border-s-4 border-trust-navy ps-4 text-sm leading-relaxed text-warm-charcoal/70">
                {{ __('app.eosi.explain') }}
            </p>

            @unless ($inputs['nssf_registered'] ?? true)
                <p class="mt-4 border-s-4 border-status-due-soon ps-4 text-sm leading-relaxed text-warm-charcoal">
                    {{ __('app.eosi.unregistered_note') }}
                </p>
            @elseif ($inputs['enrolment_assumed_from_start_date'] ?? false)
                <p class="mt-4 text-xs leading-relaxed text-warm-charcoal/70">
                    {{ __('app.eosi.enrolment_assumed') }}
                </p>
            @endunless

            @if ($canManage)
                <form method="POST" action="{{ route('app.employees.eosi.accrue', $employee) }}" class="mt-6">
                    @csrf
                    <x-button type="submit" variant="primary" size="md">{{ __('app.eosi.record') }}</x-button>
                    <p class="mt-2 text-xs leading-relaxed text-warm-charcoal/70">{{ __('app.eosi.record_hint') }}</p>
                </form>
            @endif
        </x-card>

        {{-- Bonuses and overtime, with the three-year test made visible. --}}
        <x-card tone="light" class="mt-6">
            <h2 class="font-semibold text-trust-navy">{{ __('app.eosi.elements_heading') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-warm-charcoal/70">{{ __('app.eosi.elements_intro') }}</p>

            @if ($employee->payElements->isEmpty())
                <p class="mt-4 text-warm-charcoal/70">{{ __('app.eosi.no_elements') }}</p>
            @else
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($qualifying as $kind)
                        <x-status-badge status="filed">
                            {{ __('app.eosi.kind_'.$kind) }} — {{ __('app.eosi.included') }}
                        </x-status-badge>
                    @endforeach
                    @foreach ($excluded as $kind)
                        <x-status-badge status="due-soon">
                            {{ __('app.eosi.kind_'.$kind) }} — {{ __('app.eosi.excluded') }}
                        </x-status-badge>
                    @endforeach
                </div>

                <ul class="mt-4 space-y-2">
                    @foreach ($employee->payElements as $element)
                        <li class="flex flex-wrap items-center justify-between gap-3 border-b border-warm-charcoal/8 pb-2 last:border-0">
                            <span>
                                <span class="font-medium text-warm-charcoal">{{ __('app.eosi.kind_'.$element->kind) }}</span>
                                <span class="block text-xs tabular-nums text-warm-charcoal/70" dir="ltr">{{ $element->paid_on->toDateString() }}</span>
                            </span>
                            <span class="flex items-center gap-3">
                                <span class="tabular-nums text-warm-charcoal" dir="ltr">
                                    {{ $element->amount()->format() }} {{ $element->amount_currency->value }}
                                </span>
                                @if ($canManage)
                                    <form method="POST" action="{{ route('app.employees.pay-elements.destroy', [$employee, $element]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded text-sm font-medium text-status-overdue underline-offset-4 hover:underline">
                                            {{ __('app.eosi.remove') }}
                                        </button>
                                    </form>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canManage)
                <form method="POST" action="{{ route('app.employees.pay-elements.store', $employee) }}" class="mt-6 space-y-5" novalidate>
                    @csrf
                    <div class="grid gap-5 sm:grid-cols-3">
                        <x-form.select name="kind" :label="__('app.eosi.kind')" :options="[
                            'bonus' => __('app.eosi.kind_bonus'),
                            'overtime' => __('app.eosi.kind_overtime'),
                        ]" />
                        <x-form.input
                            name="amount" :label="__('app.eosi.amount')"
                            :hint="$employee->base_salary_currency->value"
                            inputmode="decimal" dir="ltr"
                        />
                        <x-form.input name="paid_on" type="date" :label="__('app.eosi.paid_on')" dir="ltr" />
                    </div>
                    <x-button type="submit" variant="secondary" size="md">{{ __('app.eosi.add_element') }}</x-button>
                </form>
            @endif
        </x-card>

        {{-- The stored ledger. Historical statements, not a cache. --}}
        <x-card tone="light" :padded="false" class="mt-6 overflow-hidden">
            <div class="border-b border-warm-charcoal/12 bg-warm-offwhite px-5 py-4">
                <h2 class="font-semibold text-trust-navy">{{ __('app.eosi.ledger_heading') }}</h2>
            </div>

            @if ($accruals->isEmpty())
                <p class="px-5 py-6 text-warm-charcoal/70">{{ __('app.eosi.no_accruals') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wider text-warm-charcoal/70">
                            <tr class="border-b border-warm-charcoal/12">
                                <th class="px-5 py-3 text-start font-semibold">{{ __('app.eosi.as_of') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.eosi.coefficient') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('app.eosi.accrued') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('app.eosi.formula') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accruals as $accrual)
                                <tr class="border-b border-warm-charcoal/8 last:border-0">
                                    <td class="px-5 py-3 tabular-nums" dir="ltr">{{ $accrual->as_of->toDateString() }}</td>
                                    <td class="px-5 py-3 text-end tabular-nums" dir="ltr">{{ $accrual->coefficientPercent() }}</td>
                                    <td class="px-5 py-3 text-end tabular-nums" dir="ltr">
                                        {{ $accrual->accrued()->format() }} {{ $accrual->accrued_currency->value }}
                                    </td>
                                    <td class="px-5 py-3" dir="ltr">
                                        <span class="text-warm-charcoal/70">{{ $accrual->strategy }} {{ $accrual->formula_version }}</span>
                                        @unless ($accrual->matchesFormula($strategy->name(), $strategy->version()))
                                            {{-- The law moved under this row. It still says what it said. --}}
                                            <span class="block text-xs text-status-due-soon">{{ __('app.eosi.older_formula') }}</span>
                                        @endunless
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
