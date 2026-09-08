@php
    $isNew = ! $employee->exists;
    $title = $isNew ? __('app.employees.add') : __('app.employees.edit');

    /*
     | Repopulate from old() on a validation failure, otherwise from the model.
     | The salary is shown as a decimal string the person can read, built from
     | the exact minor units — never a float.
     */
    $salary = old('base_salary') ?? ($isNew ? '' : $employee->baseSalary()->format());
    $currency = old('base_salary_currency') ?? ($isNew ? 'USD' : $employee->base_salary_currency->value);
@endphp

<x-layouts.app :title="$title">
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">{{ $title }}</h1>

        <form
            method="POST"
            action="{{ $isNew ? route('app.employees.store') : route('app.employees.update', $employee) }}"
            class="mt-8"
            novalidate
        >
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            <x-card tone="light" class="space-y-5">
                <x-form.input
                    name="name"
                    :label="__('app.employees.name')"
                    :value="old('name', $employee->name)"
                    autocomplete="off"
                    required
                />

                <x-form.input
                    name="nssf_number"
                    :label="__('app.employees.nssf_number')"
                    :hint="__('app.employees.nssf_number_hint')"
                    :value="old('nssf_number', $employee->nssf_number)"
                    autocomplete="off"
                />

                <div class="grid gap-5 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <x-form.input
                            name="base_salary"
                            :label="__('app.employees.salary')"
                            :hint="__('app.employees.salary_hint')"
                            :value="$salary"
                            inputmode="decimal"
                            dir="ltr"
                            autocomplete="off"
                            required
                        />
                    </div>

                    <x-form.select
                        name="base_salary_currency"
                        :label="__('app.employees.currency')"
                        :options="['USD' => 'USD', 'LBP' => 'LBP']"
                        :selected="$currency"
                        required
                    />
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-form.input
                        name="started_on"
                        type="date"
                        :label="__('app.employees.started_on')"
                        :value="old('started_on', $employee->started_on?->toDateString())"
                        dir="ltr"
                        required
                    />
                    <x-form.input
                        name="nssf_enrolled_on"
                        type="date"
                        :label="__('app.employees.nssf_enrolled_on')"
                        :hint="__('app.employees.nssf_enrolled_on_hint')"
                        :optional="__('app.employees.same_as_start')"
                        :value="old('nssf_enrolled_on', $employee->nssf_enrolled_on?->toDateString())"
                        dir="ltr"
                    />
                    <x-form.input
                        name="ended_on"
                        type="date"
                        :label="__('app.employees.ended_on')"
                        :optional="__('app.employees.still_employed')"
                        :value="old('ended_on', $employee->ended_on?->toDateString())"
                        dir="ltr"
                    />
                </div>

                <label class="flex items-start gap-3 rounded-lg border-2 border-warm-charcoal/15 p-4">
                    <input type="hidden" name="is_nssf_registered" value="0">
                    <input
                        type="checkbox" name="is_nssf_registered" value="1"
                        @checked(old('is_nssf_registered', $isNew ? true : $employee->is_nssf_registered))
                        class="mt-0.5 size-4 shrink-0 rounded border-2 border-warm-charcoal/25 text-trust-navy focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
                    >
                    <span>
                        <span class="block font-semibold text-warm-charcoal">{{ __('app.employees.registered') }}</span>
                        <span class="block text-sm text-warm-charcoal/70">{{ __('app.employees.registered_hint') }}</span>
                    </span>
                </label>

                <fieldset class="rounded-lg border-2 border-warm-charcoal/15 p-4">
                    <legend class="px-1 text-sm text-warm-charcoal/70">{{ __('app.employees.dependents_hint') }}</legend>

                    <label class="mt-2 flex items-center gap-3">
                        <input type="hidden" name="has_dependent_spouse" value="0">
                        <input
                            type="checkbox" name="has_dependent_spouse" value="1"
                            @checked(old('has_dependent_spouse', $employee->has_dependent_spouse))
                            class="size-4 rounded border-2 border-warm-charcoal/25 text-trust-navy focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
                        >
                        <span class="font-medium text-warm-charcoal">{{ __('app.employees.spouse') }}</span>
                    </label>

                    <div class="mt-4 max-w-40">
                        <x-form.input
                            name="dependent_children"
                            type="number"
                            :label="__('app.employees.children')"
                            :value="old('dependent_children', $employee->dependent_children ?? 0)"
                            min="0" max="20" dir="ltr"
                            required
                        />
                    </div>
                </fieldset>
            </x-card>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <x-button type="submit" variant="cta" size="lg">{{ __('app.employees.save') }}</x-button>
                <x-button :href="route('app.employees.index')" variant="ghost" size="lg">
                    {{ __('app.employees.heading') }}
                </x-button>
            </div>
        </form>

        @unless ($isNew || $employee->ended_on)
            {{-- Separate form: ending employment is not a save. --}}
            <form method="POST" action="{{ route('app.employees.end', $employee) }}" class="mt-8 border-t border-warm-charcoal/12 pt-6">
                @csrf
                <x-button
                    type="submit" variant="secondary" size="md"
                    onclick="return confirm(@js(__('app.employees.end_confirm')))"
                >{{ __('app.employees.end') }}</x-button>
            </form>
        @endunless
    </div>
</x-layouts.app>
