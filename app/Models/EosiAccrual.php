<?php

namespace App\Models;

use App\Payroll\Eosi\EosiEstimate;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored end-of-service estimate for one employee on one date.
 *
 * The row records WHICH strategy and WHICH version produced it. When Law 319
 * changes the formula, this row keeps saying what it said — it is a historical
 * statement, not a cache of a current calculation.
 */
class EosiAccrual extends Model
{
    protected $fillable = [
        'employee_id',
        'as_of',
        'strategy',
        'formula_version',
        'service_months',
        'enrolment_months',
        'coefficient_bps',
        'average_salary_minor',
        'average_salary_currency',
        'accrued_minor',
        'accrued_currency',
        'inputs',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'service_months' => 'integer',
            'enrolment_months' => 'integer',
            'coefficient_bps' => 'integer',
            'average_salary_minor' => 'integer',
            'average_salary_currency' => Currency::class,
            'accrued_minor' => 'integer',
            'accrued_currency' => Currency::class,
            'inputs' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function accrued(): Money
    {
        return Money::of($this->accrued_minor, $this->accrued_currency);
    }

    public function averageSalary(): Money
    {
        return Money::of($this->average_salary_minor, $this->average_salary_currency);
    }

    public function coefficientPercent(): string
    {
        return rtrim(rtrim(number_format($this->coefficient_bps / 100, 2), '0'), '.').'%';
    }

    /** Whether this row was produced by the formula currently in force. */
    public function matchesFormula(string $strategy, string $version): bool
    {
        return $this->strategy === $strategy && $this->formula_version === $version;
    }

    public static function fromEstimate(int $employeeId, EosiEstimate $estimate, string $asOf): self
    {
        /*
         * `whereDate`, not updateOrCreate's equality match.
         *
         * The `date` cast writes "2026-09-01 00:00:00", so an equality match on
         * "2026-09-01" finds nothing and inserts a duplicate — and it does that
         * on SQLite while MySQL, which truncates a DATE column, would have
         * matched. Exactly the engine divergence that cost a dropped quarter in
         * the VAT return. whereDate is correct on both.
         */
        $existing = static::query()
            ->where('employee_id', $employeeId)
            ->whereDate('as_of', $asOf)
            ->first();

        $accrual = $existing ?? new self(['employee_id' => $employeeId, 'as_of' => $asOf]);

        $accrual->fill([
            'strategy' => $estimate->strategy,
            'formula_version' => $estimate->formulaVersion,
            'service_months' => $estimate->serviceMonths,
            'enrolment_months' => $estimate->enrolmentMonths,
            'coefficient_bps' => $estimate->coefficientBps,
            'average_salary_minor' => $estimate->averageSalary->minorUnits,
            'average_salary_currency' => $estimate->averageSalary->currency,
            'accrued_minor' => $estimate->accrued->minorUnits,
            'accrued_currency' => $estimate->accrued->currency,
            'inputs' => $estimate->inputs,
        ])->save();

        return $accrual;
    }
}
