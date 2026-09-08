@php
    $isNew = ! $customer->exists;
    $title = $isNew ? __('app.customers.add') : __('app.customers.edit');
@endphp

<x-layouts.app :title="$title">
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">{{ $title }}</h1>

        <form
            method="POST"
            action="{{ $isNew ? route('app.customers.store') : route('app.customers.update', $customer) }}"
            class="mt-8"
            novalidate
        >
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            <x-card tone="light" class="space-y-5">
                <x-form.input name="name" :label="__('app.customers.name')" :value="old('name', $customer->name)" required />

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-form.input name="phone" type="tel" :label="__('app.customers.phone')" :value="old('phone', $customer->phone)" dir="ltr" />
                    <x-form.input name="email" type="email" :label="__('app.customers.email')" :value="old('email', $customer->email)" dir="ltr" />
                </div>

                <x-form.input
                    name="vat_number" :label="__('app.customers.vat_number')"
                    :hint="__('app.customers.vat_number_hint')"
                    :value="old('vat_number', $customer->vat_number)" dir="ltr"
                />

                <x-form.input name="address" :label="__('app.customers.address')" :value="old('address', $customer->address)" />
            </x-card>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <x-button type="submit" variant="cta" size="lg">{{ __('app.customers.save') }}</x-button>
                <x-button :href="route('app.customers.index')" variant="ghost" size="lg">{{ __('app.customers.heading') }}</x-button>
            </div>
        </form>
    </div>
</x-layouts.app>
