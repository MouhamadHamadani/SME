<?php

namespace App\Payroll\Eosi;

use App\Support\Money;

/**
 * What one strategy computed, and enough of the working to defend it.
 *
 * `inputs` is not debug output — it is what gets shown to a departing employee
 * who asks how the figure was reached, and what a later reader needs when the
 * formula has since changed underneath the stored row.
 */
final readonly class EosiEstimate
{
    /**
     * @param  Money  $accrued  The estimate, in the contract's own currency.
     * @param  Money  $averageSalary  The 12-month average the coefficient was applied to.
     * @param  int  $coefficientBps  Seniority coefficient, 5000 = 50%.
     * @param  int  $enrolmentMonths  NSSF enrolment months — what chose the coefficient.
     * @param  array<string, mixed>  $inputs  The working, for audit.
     */
    public function __construct(
        public Money $accrued,
        public Money $averageSalary,
        public int $coefficientBps,
        public int $serviceMonths,
        public int $enrolmentMonths,
        public string $strategy,
        public string $formulaVersion,
        public array $inputs = [],
    ) {}

    /** Whole years of service, for display. */
    public function serviceYears(): int
    {
        return intdiv($this->serviceMonths, 12);
    }

    public function coefficientPercent(): string
    {
        return rtrim(rtrim(number_format($this->coefficientBps / 100, 2), '0'), '.').'%';
    }
}
