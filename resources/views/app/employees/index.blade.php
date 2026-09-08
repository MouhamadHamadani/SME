@php $canManage = auth()->user()->can('manage-payroll'); @endphp

<x-layouts.app :title="__('app.employees.heading')">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
            {{ __('app.employees.heading') }}
        </h1>

        @if ($canManage)
            <x-button :href="route('app.employees.create')" variant="cta" size="md">
                {{ __('app.employees.add') }}
            </x-button>
        @endif
    </div>

    @if (session('status'))
        <x-card tone="light" accent="green" class="mt-6" role="status">
            <p class="font-medium text-warm-charcoal">{{ session('status') }}</p>
        </x-card>
    @endif

    @if ($active->isEmpty() && $former->isEmpty())
        <x-card tone="light" accent="navy" class="mt-8">
            <p class="leading-relaxed text-warm-charcoal/80">{{ __('app.employees.none') }}</p>
        </x-card>
    @endif

    @foreach ([['active', $active], ['former', $former]] as [$key, $group])
        @continue($group->isEmpty())

        <h2 class="mt-10 text-lg font-semibold text-trust-navy">{{ __('app.employees.'.$key) }}</h2>

        <x-card tone="light" :padded="false" class="mt-4 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-warm-charcoal/12 bg-warm-offwhite">
                        <tr>
                            <th class="px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.employees.name') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-end font-semibold text-warm-charcoal">{{ __('app.employees.salary') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.employees.started_on') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-start font-semibold text-warm-charcoal">
                                {{ $key === 'former' ? __('app.employees.ended_on') : __('app.employees.service') }}
                            </th>
                            <th class="px-5 py-3 text-end font-semibold text-warm-charcoal"><span class="sr-only">{{ __('app.employees.edit') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group as $employee)
                            <tr class="border-b border-warm-charcoal/8 last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium text-warm-charcoal">{{ $employee->name }}</span>
                                    @unless ($employee->is_nssf_registered)
                                        <span class="ms-2 rounded-full bg-status-due-soon px-2 py-0.5 text-xs font-semibold text-warm-charcoal">
                                            {{ __('app.employees.undeclared') }}
                                        </span>
                                    @endunless
                                    @if ($employee->nssf_number)
                                        <span class="block text-xs text-warm-charcoal/70" dir="ltr">{{ $employee->nssf_number }}</span>
                                    @endif
                                </td>
                                {{-- Figures read left-to-right even on the RTL page. --}}
                                <td class="whitespace-nowrap px-5 py-3 text-end tabular-nums" dir="ltr">
                                    {{ $employee->baseSalary()->format() }}
                                    <span class="text-warm-charcoal/70">{{ $employee->base_salary_currency->value }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 tabular-nums" dir="ltr">{{ $employee->started_on->toDateString() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-warm-charcoal/80">
                                    @if ($employee->ended_on)
                                        <span dir="ltr" class="tabular-nums">{{ $employee->ended_on->toDateString() }}</span>
                                    @else
                                        {{ __('app.employees.months', ['count' => $employee->serviceMonthsAt(now())]) }}
                                    @endif
                                </td>
                                {{-- End of service is readable by any signed-in user; editing is not. --}}
                                <td class="whitespace-nowrap px-5 py-3 text-end">
                                    <a href="{{ route('app.employees.eosi', $employee) }}"
                                       class="rounded font-medium text-trust-navy underline-offset-4 hover:underline">
                                        {{ __('app.eosi.view') }}
                                    </a>
                                    @if ($canManage)
                                        <a href="{{ route('app.employees.edit', $employee) }}"
                                           class="ms-4 rounded font-medium text-trust-navy underline-offset-4 hover:underline">
                                            {{ __('app.employees.edit') }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endforeach

    @unless ($canManage)
        <p class="mt-6 text-sm text-warm-charcoal/70">{{ __('app.employees.cannot_manage') }}</p>
    @endunless
</x-layouts.app>
