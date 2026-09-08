<?php

namespace App\Payroll;

/**
 * The NSSF contribution rates and ceilings in force for one period.
 *
 * A plain value object, deliberately decoupled from the Eloquent model: a
 * payroll run SNAPSHOTS these onto itself, so recomputing a 2024 run years
 * later uses 2024's rules even if the schedule row was since corrected or
 * deleted. "Past periods keep their own rules" is a product promise, and this
 * is where it is actually enforced.
 *
 * Rates are integer basis points (850 = 8.5%). Ceilings are whole LBP.
 *
 * The three branches of Lebanese social security:
 *
 *   Sickness & maternity  employer + employee, capped at a ceiling
 *   Family allowances     employer only,       capped at a (different) ceiling
 *   End-of-service        employer only,       NO ceiling — on full salary
 */
final readonly class NssfRates
{
    public function __construct(
        public int $sicknessEmployerBps,
        public int $sicknessEmployeeBps,
        public int $sicknessCeilingLbp,
        public int $familyEmployerBps,
        public int $familyCeilingLbp,
        public int $eosiEmployerBps,
    ) {}

    /**
     * @param  array<string, int>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            sicknessEmployerBps: (int) $row['sickness_employer_bps'],
            sicknessEmployeeBps: (int) $row['sickness_employee_bps'],
            sicknessCeilingLbp: (int) $row['sickness_ceiling_lbp'],
            familyEmployerBps: (int) $row['family_employer_bps'],
            familyCeilingLbp: (int) $row['family_ceiling_lbp'],
            eosiEmployerBps: (int) $row['eosi_employer_bps'],
        );
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'sickness_employer_bps' => $this->sicknessEmployerBps,
            'sickness_employee_bps' => $this->sicknessEmployeeBps,
            'sickness_ceiling_lbp' => $this->sicknessCeilingLbp,
            'family_employer_bps' => $this->familyEmployerBps,
            'family_ceiling_lbp' => $this->familyCeilingLbp,
            'eosi_employer_bps' => $this->eosiEmployerBps,
        ];
    }
}
