<?php

namespace App\Http\Requests;

use App\Support\Currency;
use App\Support\Money;
use App\Vat\VatTreatment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-payroll');
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'currency' => ['required', Rule::enum(Currency::class)],
            // Required for USD, since VAT is declared in lira and the figure
            // has to be reproducible from a stored rate.
            'lbp_per_usd' => ['nullable', 'integer', 'min:1', Rule::requiredIf($this->input('currency') === 'USD')],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:200'],
            // Free text so "2.5" and "1,000" both survive; parsed below.
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'string', 'max:30'],
            'lines.*.treatment' => ['required', Rule::enum(VatTreatment::class)],
        ];
    }

    /** Parse quantities and prices exactly, or fail with something actionable. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $currency = Currency::from($this->input('currency'));

                foreach ((array) $this->input('lines', []) as $i => $line) {
                    try {
                        $quantity = self::parseQuantity((string) ($line['quantity'] ?? ''));
                    } catch (InvalidArgumentException $e) {
                        $validator->errors()->add("lines.{$i}.quantity", $e->getMessage());
                        $quantity = null;
                    }

                    if ($quantity !== null && $quantity <= 0) {
                        $validator->errors()->add("lines.{$i}.quantity", __('validation.gt.numeric', [
                            'attribute' => __('app.invoices.quantity'), 'value' => 0,
                        ]));
                    }

                    try {
                        $price = Money::parse((string) ($line['unit_price'] ?? ''), $currency);
                        if ($price->minorUnits < 0) {
                            $validator->errors()->add("lines.{$i}.unit_price", __('app.employees.salary_positive'));
                        }
                    } catch (InvalidArgumentException $e) {
                        $validator->errors()->add("lines.{$i}.unit_price", $e->getMessage());
                    }
                }
            },
        ];
    }

    /**
     * "2.5" -> 2500. Thousandths, integer, no float anywhere.
     */
    public static function parseQuantity(string $input): int
    {
        $clean = str_replace([',', ' '], '', trim($input));

        if ($clean === '' || ! preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $clean, $m)) {
            throw new InvalidArgumentException('Quantity must be a number with at most 3 decimal places.');
        }

        return (int) ($m[1].str_pad($m[2] ?? '', 3, '0'));
    }

    /** @return array<int, array<string, mixed>> */
    public function lineAttributes(): array
    {
        $currency = Currency::from($this->input('currency'));

        return collect((array) $this->input('lines', []))
            ->values()
            ->map(fn (array $line, int $i) => [
                'position' => $i,
                'description' => $line['description'],
                'quantity_milli' => self::parseQuantity((string) $line['quantity']),
                'unit_price_minor' => Money::parse((string) $line['unit_price'], $currency)->minorUnits,
                'treatment' => VatTreatment::from($line['treatment']),
                // Recomputed by CalculateInvoiceTotals; never trusted from input.
                'net_minor' => 0,
                'vat_minor' => 0,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function invoiceAttributes(): array
    {
        return [
            'customer_id' => (int) $this->input('customer_id'),
            'currency' => Currency::from($this->input('currency')),
            'lbp_per_usd' => $this->input('lbp_per_usd') ? (int) $this->input('lbp_per_usd') : null,
            'notes' => $this->input('notes') ?: null,
        ];
    }

    public function dueDays(): ?int
    {
        return $this->filled('due_days') ? (int) $this->input('due_days') : null;
    }
}
