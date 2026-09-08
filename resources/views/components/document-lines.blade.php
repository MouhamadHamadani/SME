@props([
    'lines' => [],
    'treatments' => [],
    // Shown under each row; the credit-note form uses it to say what the
    // original invoice line was, so a partial credit can be judged against it.
    'originals' => null,
])

{{--
    Editable line rows for an invoice or a credit note.
    Extracted because both documents edit lines identically — two copies of
    this markup and its renumbering script would drift.
--}}

<div id="lines" class="space-y-4">
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

            <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-12">
                <button type="button" class="remove-line rounded text-sm font-medium text-status-overdue underline-offset-4 hover:underline">
                    {{ __('app.invoices.remove_line') }}
                </button>

                @if ($originals && isset($originals[$i]))
                    <span class="text-xs text-warm-charcoal/70">
                        {{ __('app.credit_notes.original_line', ['detail' => $originals[$i]]) }}
                    </span>
                @endif
            </div>
        </div>
    @endforeach
</div>

<button
    type="button" id="add-line"
    class="mt-4 rounded-lg border-2 border-trust-navy px-4 py-2 text-sm font-semibold text-trust-navy transition-colors hover:bg-trust-navy hover:text-warm-offwhite focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
>{{ __('app.invoices.add_line') }}</button>

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
            clone.querySelectorAll('p, span').forEach(function (note) { note.remove(); });
            list.appendChild(clone);
            renumber();
            clone.querySelector('input').focus();
        });

        list.addEventListener('click', function (event) {
            if (!event.target.classList.contains('remove-line')) return;
            // Never remove the last row — a document with no lines cannot be
            // issued, and an empty form is a dead end.
            if (list.querySelectorAll('.line').length === 1) return;
            event.target.closest('.line').remove();
            renumber();
        });
    })();
</script>
