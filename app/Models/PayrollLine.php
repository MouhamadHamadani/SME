<?php

namespace App\Models;

use App\Payroll\NssfBreakdown;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's month.
 *
 * Stored in LBP (NSSF's currency of record) plus the salary as contracted.
 * USD figures are derived from the run's stored rate — never stored twice.
 */
class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'employee_name',
        'is_nssf_registered',
        'contracted_salary_minor',
        'contracted_salary_currency',
        'gross_lbp',
        'sickness_base_lbp',
        'sickness_employer_lbp',
        'sickness_employee_lbp',
        'family_base_lbp',
        'family_employer_lbp',
        'eosi_base_lbp',
        'eosi_employer_lbp',
    ];

    protected function casts(): array
    {
        return [
            'is_nssf_registered' => 'boolean',
            'contracted_salary_minor' => 'integer',
            'contracted_salary_currency' => Currency::class,
            'gross_lbp' => 'integer',
            'sickness_base_lbp' => 'integer',
            'sickness_employer_lbp' => 'integer',
            'sickness_employee_lbp' => 'integer',
            'family_base_lbp' => 'integer',
            'family_employer_lbp' => 'integer',
            'eosi_base_lbp' => 'integer',
            'eosi_employer_lbp' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return array<string, int|bool> */
    public static function fromBreakdown(NssfBreakdown $b, Employee $employee): array
    {
        return [
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'is_nssf_registered' => $employee->is_nssf_registered,
            'contracted_salary_minor' => $employee->base_salary_minor,
            'contracted_salary_currency' => $employee->base_salary_currency->value,
            'gross_lbp' => $b->gross->minorUnits,
            'sickness_base_lbp' => $b->sicknessBase->minorUnits,
            'sickness_employer_lbp' => $b->sicknessEmployer->minorUnits,
            'sickness_employee_lbp' => $b->sicknessEmployee->minorUnits,
            'family_base_lbp' => $b->familyBase->minorUnits,
            'family_employer_lbp' => $b->familyEmployer->minorUnits,
            'eosi_base_lbp' => $b->eosiBase->minorUnits,
            'eosi_employer_lbp' => $b->eosiEmployer->minorUnits,
        ];
    }

    public function gross(): Money
    {
        return Money::lbp($this->gross_lbp);
    }

    public function employeeDeductions(): Money
    {
        return Money::lbp($this->sickness_employee_lbp);
    }

    public function employerContributions(): Money
    {
        return Money::lbp(
            $this->sickness_employer_lbp + $this->family_employer_lbp + $this->eosi_employer_lbp
        );
    }

    public function net(): Money
    {
        return $this->gross()->minus($this->employeeDeductions());
    }

    public function contractedSalary(): Money
    {
        return Money::of($this->contracted_salary_minor, $this->contracted_salary_currency);
    }
}
