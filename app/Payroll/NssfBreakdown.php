<?php

namespace App\Payroll;

use App\Support\Money;

/**
 * What one employee costs for one month, broken out the way an NSSF
 * declaration and a payslip both need it.
 *
 * Everything is in LBP: NSSF is declared in lira regardless of what currency
 * the salary was agreed in. The USD view is derived from the run's stored
 * exchange rate, never stored twice.
 */
final readonly class NssfBreakdown
{
    public function __construct(
        public Money $gross,
        public Money $sicknessBase,
        public Money $sicknessEmployer,
        public Money $sicknessEmployee,
        public Money $familyBase,
        public Money $familyEmployer,
        public Money $eosiBase,
        public Money $eosiEmployer,
    ) {}

    /** Withheld from the employee. Only the sickness branch has an employee share. */
    public function employeeDeductions(): Money
    {
        return $this->sicknessEmployee;
    }

    /** Paid by the business on top of the salary. */
    public function employerContributions(): Money
    {
        return $this->sicknessEmployer
            ->plus($this->familyEmployer)
            ->plus($this->eosiEmployer);
    }

    /** What the employee actually receives. */
    public function net(): Money
    {
        return $this->gross->minus($this->employeeDeductions());
    }

    /** Total cost to the business: gross plus the employer's contributions. */
    public function totalEmployerCost(): Money
    {
        return $this->gross->plus($this->employerContributions());
    }

    /** True when the salary exceeded a ceiling and contributions were capped. */
    public function isCapped(): bool
    {
        return $this->gross->isGreaterThan($this->sicknessBase)
            || $this->gross->isGreaterThan($this->familyBase);
    }
}
