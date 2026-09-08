<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Support\Money;
use App\Vat\VatTreatment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class CreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-payroll');
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'string', 'max:30'],
            'lines.*.treatment' => ['required', Rule::enum(VatTreatment::class)],
        ];
    }

    /**
     * Parse quantities and prices exactly.
     *
     * Currency is NOT a form field: a credit note is always denominated in the
     * currency of the invoice it corrects, so it is read from there rather
     * than trusted from input.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $currency = $this->invoice()->currency;

                foreach ((array) $this->input('lines', []) as $i => $line) {
                    try {
                        if (InvoiceRequest::parseQuantity((string) ($line['quantity'] ?? '')) <= 0) {
                            $validator->errors()->add("lines.{$i}.quantity", __('app.credit_notes.amount_positive'));
                        }
                    } catch (InvalidArgumentException $e) {
                        $validator->errors()->add("lines.{$i}.quantity", $e->getMessage());
                    }

                    try {
                        if (Money::parse((string) ($line['unit_price'] ?? ''), $currency)->minorUnits <= 0) {
                            $validator->errors()->add("lines.{$i}.unit_price", __('app.credit_notes.amount_positive'));
                        }
                    } catch (InvalidArgumentException $e) {
                        $validator->errors()->add("lines.{$i}.unit_price", $e->getMessage());
                    }
                }
            },
        ];
    }

    public function invoice(): Invoice
    {
        return $this->route('invoice');
    }

    /** @return array<int, array<string, mixed>> */
    public function lineAttributes(): array
    {
        $currency = $this->invoice()->currency;

        return collect((array) $this->input('lines', []))
            ->values()
            ->map(fn (array $line, int $i) => [
                'position' => $i,
                'description' => $line['description'],
                'quantity_milli' => InvoiceRequest::parseQuantity((string) $line['quantity']),
                'unit_price_minor' => Money::parse((string) $line['unit_price'], $currency)->minorUnits,
                'treatment' => VatTreatment::from($line['treatment']),
                // Recomputed by CalculateInvoiceTotals; never trusted from input.
                'net_minor' => 0,
                'vat_minor' => 0,
            ])
            ->all();
    }
}
