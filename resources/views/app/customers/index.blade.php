@php $canManage = auth()->user()->can('manage-payroll'); @endphp

<x-layouts.app :title="__('app.customers.heading')">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
            {{ __('app.customers.heading') }}
        </h1>
        @if ($canManage)
            <x-button :href="route('app.customers.create')" variant="cta" size="md">
                {{ __('app.customers.add') }}
            </x-button>
        @endif
    </div>

    @if (session('status'))
        <x-card tone="light" accent="green" class="mt-6" role="status">
            <p class="font-medium text-warm-charcoal">{{ session('status') }}</p>
        </x-card>
    @endif

    @if ($customers->isEmpty())
        <x-card tone="light" accent="navy" class="mt-8">
            <p class="leading-relaxed text-warm-charcoal/80">{{ __('app.customers.none') }}</p>
        </x-card>
    @else
        <x-card tone="light" :padded="false" class="mt-8 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-warm-charcoal/12 bg-warm-offwhite">
                        <tr>
                            <th class="px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.customers.name') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.customers.phone') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-start font-semibold text-warm-charcoal">{{ __('app.customers.vat_number') }}</th>
                            @if ($canManage)
                                <th class="px-5 py-3 text-end font-semibold text-warm-charcoal"><span class="sr-only">{{ __('app.customers.edit') }}</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            <tr class="border-b border-warm-charcoal/8 last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium text-warm-charcoal">{{ $customer->name }}</span>
                                    <span class="block text-xs text-warm-charcoal/70">
                                        {{ __('app.customers.invoices_count', ['count' => $customer->invoices_count]) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-warm-charcoal/80" dir="ltr">{{ $customer->phone ?: '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 tabular-nums text-warm-charcoal/80" dir="ltr">{{ $customer->vat_number ?: '—' }}</td>
                                @if ($canManage)
                                    <td class="whitespace-nowrap px-5 py-3 text-end">
                                        <a href="{{ route('app.customers.edit', $customer) }}"
                                           class="rounded font-medium text-trust-navy underline-offset-4 hover:underline">
                                            {{ __('app.customers.edit') }}
                                        </a>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-layouts.app>
