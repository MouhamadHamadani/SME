@php
    $isNew = ! $invoice->exists;
    $title = $isNew ? __('app.invoices.new') : __('app.invoices.edit');

    // Repopulate from old() after a validation failure, else from the model.
    $oldLines = old('lines');
    $lines = $oldLines ?: $invoice->lines->map(fn ($l) => [
        'description' => $l->description,
        'quantity' => $l->quantity(),
        'unit_price' => $l->unitPrice()->format(),
        'treatment' => $l->treatment->value,
    ])->all();

    if (empty($lines)) {
        $lines = [['description' => '', 'quantity' => '1', 'unit_price' => '', 'treatment' => 'standard']];
    }

    $treatments = ['standard' => __('app.invoices.standard'), 'zero_rated' => __('app.invoices.zero_rated'), 'exempt' => __('app.invoices.exempt')];
@endphp

<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">{{ $title }}</h1>
        <x-button :href="route('app.invoices.index')" variant="ghost" size="md">{{ __('app.invoices.back') }}</x-button>
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

    @if ($customers->isEmpty())
        <x-card tone="light" accent="due-soon" class="mt-8">
            <p class="leading-relaxed text-warm-charcoal">{{ __('app.invoices.no_customers') }}</p>
            <x-button :href="route('app.customers.create')" variant="primary" size="md" class="mt-4">
                {{ __('app.customers.add') }}
            </x-button>
        </x-card>
    @else
        <form
            method="POST"
            action="{{ $isNew ? route('app.invoices.store') : route('app.invoices.update', $invoice) }}"
            class="mt-8"
            novalidate
        >
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            <x-card tone="light">
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2">
                        <x-form.select
                            name="customer_id"
                            :label="__('app.invoices.customer')"
                            :options="$customers->pluck('name', 'id')->all()"
                            :selected="old('customer_id', $invoice->customer_id)"
                            :placeholder="__('app.invoices.customer')"
                            required
                        />
                    </div>

                    <x-form.select
                        name="currency"
                        :label="__('app.invoices.currency')"
                        :options="['LBP' => 'LBP', 'USD' => 'USD']"
                        :selected="old('currency', $invoice->currency?->value ?? 'LBP')"
                        required
                    />

                    <x-form.input
                        name="lbp_per_usd" type="number"
                        :label="__('app.invoices.rate')"
                        :hint="__('app.invoices.rate_hint')"
                        :value="old('lbp_per_usd', $invoice->lbp_per_usd ?: ($defaultRate ?: null))"
                        min="1" dir="ltr"
                    />
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <x-form.input
                        name="due_days" type="number"
                        :label="__('app.invoices.due_days')"
                        :hint="__('app.invoices.due_days_hint')"
                        :value="old('due_days', 30)"
                        min="0" max="365" dir="ltr"
                    />
                    <x-form.input
                        name="notes"
                        :label="__('app.invoices.notes')"
                        :value="old('notes', $invoice->notes)"
                    />
                </div>
            </x-card>

            <x-card tone="light" class="mt-6">
                <h2 class="text-lg font-semibold text-trust-navy">{{ __('app.invoices.lines') }}</h2>

                @error('lines')
                    <p class="mt-2 text-sm font-medium text-status-overdue">{{ $message }}</p>
                @enderror

                <div class="mt-4"><x-document-lines :lines="$lines" :treatments="$treatments" /></div>
            </x-card>

            @unless ($isNew)
                <x-card tone="muted" class="mt-6">
                    <dl class="space-y-2 text-warm-charcoal">
                        @foreach ([['subtotal', $invoice->subtotal()], ['vat', $invoice->vat()], ['grand_total', $invoice->total()]] as [$key, $amount])
                            <div class="flex items-baseline justify-between gap-3 @if($key === 'grand_total') border-t border-warm-charcoal/15 pt-2 text-lg font-bold text-trust-navy @endif">
                                <dt>{{ __('app.invoices.'.$key) }}</dt>
                                <dd class="tabular-nums" dir="ltr">{{ $amount->format() }} {{ $invoice->currency->value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-card>
            @endunless

            <div class="mt-6 flex flex-wrap gap-3">
                <x-button type="submit" variant="primary" size="lg">{{ __('app.invoices.save_draft') }}</x-button>
            </div>
        </form>

        @unless ($isNew)
            {{-- Issuing is a separate form: it is a different, irreversible act. --}}
            <form method="POST" action="{{ route('app.invoices.issue', $invoice) }}" class="mt-4">
                @csrf
                <input type="hidden" name="due_days" value="{{ old('due_days', 30) }}">
                <x-button
                    type="submit" variant="cta" size="lg"
                    onclick="return confirm(@js(__('app.invoices.issue_confirm')))"
                >{{ __('app.invoices.issue') }}</x-button>
            </form>
        @endunless
    @endif

    
</x-layouts.app>
