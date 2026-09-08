<?php

namespace App\Payroll\Eosi;

use App\Support\Money;

/**
 * End-of-service under the current lump-sum model (§3.2).
 *
 *   indemnity = average taxable salary over the last 12 months
 *             x years of service
 *             x seniority coefficient
 *
 * The seniority coefficient goes by years of NSSF enrolment:
 *
 *   under 5 years   50%
 *   5 to under 10   65%
 *   10 to under 20  85%
 *   20 and over    100%
 *
 * The boundaries are inclusive at the lower end: five years exactly is 65%,
 * not 50%. Getting that backwards costs a fifteen-per-cent step on someone's
 * final payment, which is why the tests pin 4-vs-5, 9-vs-10 and 19-vs-20.
 *
 * Overtime and bonuses are EXCLUDED from the base — unless the same kind was
 * paid consistently for the preceding three years or more, in which case §3.2
 * includes it. See `qualifyingKinds()`.
 *
 * Pure by contract: no database, no clock. Everything comes from EosiInput.
 */
final class LumpSumStrategy implements EosiStrategy
{
    /**
     * Lower bound in whole years => coefficient in basis points.
     * Ordered high to low so the first match wins.
     */
    private const COEFFICIENTS = [
        20 => 10_000,
        10 => 8_500,
        5 => 6_500,
        0 => 5_000,
    ];

    /** How many consecutive preceding years an element must appear in to count. */
    private const CONSISTENCY_YEARS = 3;

    public function name(): string
    {
        return 'lump_sum';
    }

    public function version(): string
    {
        return '2026.1';
    }

    public function estimate(EosiInput $input): EosiEstimate
    {
        // The coefficient goes by ENROLMENT years (§3.2); the multiplier below
        // goes by SERVICE years. Two clocks, deliberately not conflated.
        $coefficientBps = self::coefficientBpsFor($input->coefficientMonths());
        $qualifying = $this->qualifyingKinds($input);
        $averageSalary = $this->averageMonthlySalary($input, $qualifying);

        /*
         * One division, not three. average x coefficient/10000 x months/12
         * collapses to average x (coefficient x months) / 120000, so the
         * result rounds once instead of compounding a rounding error at each
         * step. This is money paid to a person.
         */
        $accrued = $averageSalary->scaleBy(
            $coefficientBps * $input->serviceMonths,
            10_000 * 12,
        );

        return new EosiEstimate(
            accrued: $accrued,
            averageSalary: $averageSalary,
            coefficientBps: $coefficientBps,
            serviceMonths: $input->serviceMonths,
            enrolmentMonths: $input->coefficientMonths(),
            strategy: $this->name(),
            formulaVersion: $this->version(),
            inputs: [
                'base_salary_minor' => $input->baseSalary->minorUnits,
                'currency' => $input->baseSalary->currency->value,
                'service_months' => $input->serviceMonths,
                'enrolment_months' => $input->coefficientMonths(),
                'coefficient_bps' => $coefficientBps,
                'qualifying_kinds' => $qualifying,
                'excluded_kinds' => array_values(array_diff($input->kinds(), $qualifying)),
                'consistency_years_required' => self::CONSISTENCY_YEARS,
                'average_salary_minor' => $averageSalary->minorUnits,
                'as_of' => $input->asOf->toDateString(),
                'nssf_registered' => $input->isNssfRegistered,
                // True when no enrolment date was recorded and the start date
                // stood in for it — the ordinary case, worth saying out loud
                // on a row that decides someone's final payment.
                'enrolment_assumed_from_start_date' => $input->enrolmentMonths === null,
            ],
        );
    }

    /**
     * The coefficient for a length of NSSF ENROLMENT.
     *
     * Public and static because the boundaries are the part most worth testing
     * directly, without assembling a whole input to reach them.
     */
    public static function coefficientBpsFor(int $enrolmentMonths): int
    {
        $years = intdiv(max(0, $enrolmentMonths), 12);

        foreach (self::COEFFICIENTS as $lowerBound => $bps) {
            if ($years >= $lowerBound) {
                return $bps;
            }
        }

        return 5_000;
    }

    /**
     * Which element kinds clear §3.2's consistency test.
     *
     * A kind qualifies when it appears in EACH of the three 12-month windows
     * ending at `asOf`. One large bonus in a good year is not consistency; a
     * modest one every year is. A gap in any window disqualifies the kind
     * entirely rather than counting the years around the gap.
     *
     * @return array<int, string>
     */
    private function qualifyingKinds(EosiInput $input): array
    {
        $qualifying = [];

        foreach ($input->kinds() as $kind) {
            $paidInEveryYear = true;

            for ($year = 0; $year < self::CONSISTENCY_YEARS; $year++) {
                $windowEnd = $input->asOf->copy()->subYears($year);
                $windowStart = $input->asOf->copy()->subYears($year + 1)->addDay();

                if ($input->elementsBetween($kind, $windowStart, $windowEnd) === []) {
                    $paidInEveryYear = false;
                    break;
                }
            }

            if ($paidInEveryYear) {
                $qualifying[] = $kind;
            }
        }

        return $qualifying;
    }

    /**
     * Average monthly salary over the last 12 months.
     *
     * Base salary is the contracted monthly figure. Qualifying elements paid
     * inside the last 12 months are spread across those 12 months — that is
     * what makes them part of an *average monthly* salary rather than a
     * one-off added on top.
     */
    private function averageMonthlySalary(EosiInput $input, array $qualifyingKinds): Money
    {
        $average = $input->baseSalary;

        if ($qualifyingKinds === []) {
            return $average;
        }

        $windowStart = $input->asOf->copy()->subYear()->addDay();
        $qualifyingTotal = Money::zero($input->baseSalary->currency);

        foreach ($qualifyingKinds as $kind) {
            foreach ($input->elementsBetween($kind, $windowStart, $input->asOf) as $element) {
                // plus() throws on a currency mismatch — an element paid in a
                // different currency from the contract is a real question for
                // a person, not something to convert at an assumed rate.
                $qualifyingTotal = $qualifyingTotal->plus($element->amount);
            }
        }

        return $average->plus($qualifyingTotal->scaleBy(1, 12));
    }
}
