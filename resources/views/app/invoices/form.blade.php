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

                <div id="lines" class="mt-4 space-y-4">
                    @foreach ($lines as $i => $line)
                        <div class="line grid gap-3 rounded-lg border border-warm-charcoal/12 p-4 sm:grid-cols-12">
                            <div class="sm:col-span-5">
                                <label class="block text-sm font-semibold text-warm-charcoal" for="lines-{{ $i }}-description">
                                    {{ __('app.invoices.line_description') }}
                                </label>
                                <input
                                    id="lines-{{ $i }}-description" name="lines[{{ $i }}][description]" type="text"
                                    value="{{ $line['description'] ?? '' }}"
                                    class="mt-1.5 w-full rounded-lg border-2 border-warm-charcoal/20 bg-white px-3 py-2 text-warm-charcoal focus:border-trust-navy focus:outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
                                >
                                @error("lines.$i.description")
                                    <p class="mt-1 text-sm font-medium text-status-overdue">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block text-sm font-semibold text-warm-charcoal" for="lines-{{ $i }}-quantity">
                                    {{ __('app.invoices.quantity') }}
                                </label>
                                <input
                                    id="lines-{{ $i }}-quantity" name="lines[{{ $i }}][quantity]" type="text" dir="ltr"
                                    inputmode="decimal" value="{{ $line['quantity'] ?? '1' }}"
                                    class="mt-1.5 w-full rounded-lg border-2 border-warm-charcoal/20 bg-white px-3 py-2 text-warm-charcoal focus:border-trust-navy focus:outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
                                >
                                @error("lines.$i.quantity")
                                    <p class="mt-1 text-sm font-medium text-status-overdue">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-3">
                                <label class="block text-sm font-semibold text-warm-charcoal" for="lines-{{ $i }}-unit_price">
                                    {{ __('app.invoices.unit_price') }}
                                </label>
                                <input
                                    id="lines-{{ $i }}-unit_price" name="lines[{{ $i }}][unit_price]" type="text" dir="ltr"
                                    inputmode="decimal" value="{{ $line['unit_price'] ?? '' }}"
                                    class="mt-1.5 w-full rounded-lg border-2 border-warm-charcoal/20 bg-white px-3 py-2 text-warm-charcoal focus:border-trust-navy focus:outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
                                >
                                @error("lines.$i.unit_price")
                                    <p class="mt-1 text-sm font-medium text-status-overdue">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block text-sm font-semibold text-warm-charcoal" for="lines-{{ $i }}-treatment">
                                    {{ __('app.invoices.treatment') }}
                                </label>
                                <select
                                    id="lines-{{ $i }}-treatment" name="lines[{{ $i }}][treatment]"
                                    class="mt-1.5 w-full appearance-none rounded-lg border-2 border-warm-charcoal/20 bg-white px-3 py-2 text-warm-charcoal focus:border-trust-navy focus:outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
                                >
                                    @foreach ($treatments as $value => $label)
                                        <option value="{{ $value }}" @selected(($line['treatment'] ?? 'standard') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-12">
                                <button type="button" class="remove-line rounded text-sm font-medium text-status-overdue underline-offset-4 hover:underline">
                                    {{ __('app.invoices.remove_line') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <button
                    type="button" id="add-line"
                    class="mt-4 rounded-lg border-2 border-trust-navy px-4 py-2 text-sm font-semibold text-trust-navy transition-colors hover:bg-trust-navy hover:text-warm-offwhite focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
                >{{ __('app.invoices.add_line') }}</button>
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

    {{-- Line rows, without a framework. Clones the last row, renumbers every
         name/id so the array indexes stay contiguous, and clears the values. --}}
    <script>
        (function () {
            const list = document.getElementById('lines');
            const add = document.getElementById('add-line');
            if (!list || !add) return;

            function renumber() {
                list.querySelectorAll('.line').forEach(function (row, i) {
                    row.querySelectorAll('input, select').forEach(function (field) {
                        const key = field.name.match(/\[([a-z_]+)\]$/);
                        if (!key) return;
                        field.name = 'lines[' + i + '][' + key[1] + ']';
                        field.id = 'lines-' + i + '-' + key[1];
                        const label = row.querySelector('label[for$="-' + key[1] + '"]');
                        if (label) label.htmlFor = field.id;
                    });
                });
            }

            add.addEventListener('click', function () {
                const rows = list.querySelectorAll('.line');
                const clone = rows[rows.length - 1].cloneNode(true);
                clone.querySelectorAll('input').forEach(function (field) {
                    field.value = field.name.indexOf('[quantity]') !== -1 ? '1' : '';
                });
                clone.querySelectorAll('p').forEach(function (error) { error.remove(); });
                list.appendChild(clone);
                renumber();
                clone.querySelector('input').focus();
            });

            list.addEventListener('click', function (event) {
                if (!event.target.classList.contains('remove-line')) return;
                // Never remove the last row — an invoice with no lines cannot
                // be issued, and an empty form is a dead end.
                if (list.querySelectorAll('.line').length === 1) return;
                event.target.closest('.line').remove();
                renumber();
            });
        })();
    </script>
</x-layouts.app>
