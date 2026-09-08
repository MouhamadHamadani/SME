<?php

namespace App\Payroll\Eosi;

use App\Models\Employee;
use App\Models\EosiAccrual;
use Illuminate\Support\Carbon;

/**
 * Reads an employee, runs the strategy, stores the estimate.
 *
 * The database work lives here so `EosiStrategy` can stay pure — the same
 * split as `NssfCalculator` and `RunPayroll`. A strategy that could reach the
 * database could not be reproduced years later from its stored inputs.
 */
class AccrueEosi
{
    public function __construct(private readonly EosiStrategy $strategy) {}

    /** Compute without storing — what the screen shows. */
    public function estimate(Employee $employee, ?Carbon $asOf = null): EosiEstimate
    {
        return $this->strategy->estimate($this->inputFor($employee, $asOf ?? Carbon::now()));
    }

    /** Compute and record. Re-running for the same day corrects that day's row. */
    public function accrue(Employee $employee, ?Carbon $asOf = null): EosiAccrual
    {
        $asOf ??= Carbon::now();

        return EosiAccrual::fromEstimate(
            $employee->id,
            $this->estimate($employee, $asOf),
            $asOf->toDateString(),
        );
    }

    /** @return array{0: int, 1: int} accrued count, skipped count */
    public function accrueAll(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();
        $accrued = 0;
        $skipped = 0;

        Employee::query()
            ->with('payElements')
            ->whereNull('ended_on')
            ->orderBy('id')
            ->each(function (Employee $employee) use ($asOf, &$accrued, &$skipped) {
                // Someone hired today has no service and no indemnity. Storing
                // a zero row for them is noise, not information.
                if ($employee->serviceMonthsAt($asOf) < 1) {
                    $skipped++;

                    return;
                }

                $this->accrue($employee, $asOf);
                $accrued++;
            });

        return [$accrued, $skipped];
    }

    private function inputFor(Employee $employee, Carbon $asOf): EosiInput
    {
        return new EosiInput(
            baseSalary: $employee->baseSalary(),
            serviceMonths: $employee->serviceMonthsAt($asOf),
            asOf: $asOf,
            // Null when no enrolment date is recorded, which EosiInput reads
            // as "declared from the start date".
            enrolmentMonths: $employee->nssf_enrolled_on || ! $employee->is_nssf_registered
                ? $employee->enrolmentMonthsAt($asOf)
                : null,
            payElements: $employee->payElements
                ->map(fn ($element) => $element->toPayElement())
                ->all(),
            isNssfRegistered: (bool) $employee->is_nssf_registered,
        );
    }
}
