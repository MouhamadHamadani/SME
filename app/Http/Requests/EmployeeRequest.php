<?php

namespace App\Http\Requests;

use App\Support\Currency;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-payroll');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'nssf_number' => ['nullable', 'string', 'max:40'],
            // Free text so a spreadsheet paste ("1,200,000") survives; the
            // exact parse happens below, in Money.
            'base_salary' => ['required', 'string', 'max:30'],
            'base_salary_currency' => ['required', Rule::enum(Currency::class)],
            'has_dependent_spouse' => ['boolean'],
            'dependent_children' => ['required', 'integer', 'min:0', 'max:20'],
            'is_nssf_registered' => ['boolean'],
            'started_on' => ['required', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:started_on'],
        ];
    }

    /**
     * Turn the typed salary into exact minor units, or fail with a message the
     * person can act on. Doing it here keeps floats out of the controller.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['base_salary', 'base_salary_currency'])) {
                    return;
                }

                try {
                    $money = Money::parse(
                        (string) $this->input('base_salary'),
                        Currency::from($this->input('base_salary_currency')),
                    );
                } catch (\InvalidArgumentException $e) {
                    $validator->errors()->add('base_salary', $e->getMessage());

                    return;
                }

                if ($money->minorUnits <= 0) {
                    $validator->errors()->add('base_salary', __('app.employees.salary_positive'));
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        $currency = Currency::from($this->input('base_salary_currency'));

        return [
            'name' => $this->input('name'),
            'nssf_number' => $this->input('nssf_number') ?: null,
            'base_salary_minor' => Money::parse((string) $this->input('base_salary'), $currency)->minorUnits,
            'base_salary_currency' => $currency,
            'has_dependent_spouse' => $this->boolean('has_dependent_spouse'),
            'dependent_children' => (int) $this->input('dependent_children', 0),
            'is_nssf_registered' => $this->boolean('is_nssf_registered'),
            'started_on' => $this->date('started_on'),
            'ended_on' => $this->input('ended_on') ? $this->date('ended_on') : null,
        ];
    }
}
