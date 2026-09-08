<?php

namespace App\Payroll;

use App\Support\Currency;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Lebanese NSSF contributions for one employee, one month.
 *
 * Pure: rates in, numbers out, no database and no clock. That is what makes it
 * testable against worked examples, and what lets a run be recomputed years
 * later from its snapshotted rates.
 *
 * The rules it implements:
 *
 *   Sickness & maternity — employer and employee both contribute, on the
 *     salary capped at the sickness ceiling.
 *   Family allowances    — employer only, on the salary capped at the family
 *     ceiling, which is a DIFFERENT (lower) ceiling. Whether the employee
 *     actually has dependents does not change the contribution; it affects
 *     the benefit NSSF pays back, which is a separate flow.
 *   End of service       — employer only, on the FULL salary. No ceiling.
 *     Forgetting this is the classic Lebanese payroll bug: capping EOSI
 *     under-contributes for every employee above the ceiling.
 */
final class NssfCalculator
{
    public function calculate(Money $grossLbp, NssfRates $rates): NssfBreakdown
    {
        if ($grossLbp->currency !== Currency::LBP) {
            throw new InvalidArgumentException(
                'NSSF is calculated in LBP. Convert the salary at the run\'s exchange rate first.'
            );
        }

        if ($grossLbp->minorUnits < 0) {
            throw new InvalidArgumentException('Gross salary cannot be negative.');
        }

        $sicknessBase = $grossLbp->min(Money::lbp($rates->sicknessCeilingLbp));
        $familyBase = $grossLbp->min(Money::lbp($rates->familyCeilingLbp));
        // No ceiling on end-of-service — deliberately the full gross.
        $eosiBase = $grossLbp;

        return new NssfBreakdown(
            gross: $grossLbp,
            sicknessBase: $sicknessBase,
            sicknessEmployer: $sicknessBase->percentBps($rates->sicknessEmployerBps),
            sicknessEmployee: $sicknessBase->percentBps($rates->sicknessEmployeeBps),
            familyBase: $familyBase,
            familyEmployer: $familyBase->percentBps($rates->familyEmployerBps),
            eosiBase: $eosiBase,
            eosiEmployer: $eosiBase->percentBps($rates->eosiEmployerBps),
        );
    }

    /**
     * An employee who is not declared to the NSSF still appears on the payroll,
     * but with no contributions on either side.
     *
     * This is not an edge case in Lebanon — it is common enough that modelling
     * it explicitly is safer than letting someone fake it with zeroed rates.
     */
    public function calculateUnregistered(Money $grossLbp): NssfBreakdown
    {
        $zero = Money::zero(Currency::LBP);

        return new NssfBreakdown(
            gross: $grossLbp,
            sicknessBase: $zero,
            sicknessEmployer: $zero,
            sicknessEmployee: $zero,
            familyBase: $zero,
            familyEmployer: $zero,
            eosiBase: $zero,
            eosiEmployer: $zero,
        );
    }
}
