<?php

namespace App\Http\Requests;

use App\Support\Currency;
use App\Support\Money;
use App\Support\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class PaymentRequest extends FormRequest
{
    /** Same gate as the rest of invoicing: owner and accountant record, staff read. */
    public function authorize(): bool
    {
        return $this->user()->can('manage-payroll');
    }

    public function rules(): array
    {
        return [
            // Free text so "1,000,000" and "500.50" both survive; parsed below
            // through Money, which is string arithmetic all the way down.
            'amount' => ['required', 'string', 'max:30'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $amount = $this->amount();
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('amount', $e->getMessage());

                    return;
                }

                if ($amount->minorUnits <= 0) {
                    $validator->errors()->add('amount', __('app.payments.amount_positive'));
                }
            },
        ];
    }

    public function amount(): Money
    {
        return Money::parse((string) $this->input('amount'), Currency::from($this->input('currency')));
    }

    public function method(): PaymentMethod
    {
        return PaymentMethod::from($this->input('method'));
    }
}
