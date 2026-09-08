<?php

namespace App\Payroll\Eosi;

use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Everything a strategy needs to produce an estimate. No database, no clock.
 *
 * The strategy is handed ALL pay elements rather than a pre-filtered list,
 * because which ones count is part of the formula and a future pension
 * strategy may well answer that differently. Filtering here would bake one
 * law's rule into the input.
 */
final readonly class EosiInput
{
    /**
     * `serviceMonths` and `enrolmentMonths` are two different clocks and §3.2
     * uses each for a different term: years of SERVICE multiply the average
     * salary, years of NSSF ENROLMENT choose the seniority coefficient. They
     * coincide for someone declared from day one and diverge for everyone
     * declared late.
     *
     * @param  Money  $baseSalary  Monthly, in the currency the contract was agreed in.
     * @param  int  $serviceMonths  Whole months from the start date to `asOf`.
     * @param  int  $enrolmentMonths  Whole months of NSSF enrolment at `asOf`.
     * @param  array<int, PayElement>  $payElements  Every recorded element, any date.
     * @param  bool  $isNssfRegistered  Declared to the NSSF at all.
     */
    public function __construct(
        public Money $baseSalary,
        public int $serviceMonths,
        public Carbon $asOf,
        public ?int $enrolmentMonths = null,
        public array $payElements = [],
        public bool $isNssfRegistered = true,
    ) {}

    /**
     * The months the seniority coefficient is chosen by.
     *
     * Named for its purpose rather than its source: null enrolment means
     * "declared from the start date", which is the ordinary case, and the
     * fallback lives here so no caller has to remember it.
     */
    public function coefficientMonths(): int
    {
        return $this->enrolmentMonths ?? $this->serviceMonths;
    }

    /** Elements of one kind paid within a window, inclusive of both ends. */
    public function elementsBetween(string $kind, Carbon $from, Carbon $to): array
    {
        return array_values(array_filter(
            $this->payElements,
            fn (PayElement $e) => $e->kind === $kind
                && $e->paidOn->greaterThanOrEqualTo($from)
                && $e->paidOn->lessThanOrEqualTo($to),
        ));
    }

    /** @return array<int, string> the distinct kinds present, in a stable order */
    public function kinds(): array
    {
        $kinds = array_unique(array_map(fn (PayElement $e) => $e->kind, $this->payElements));
        sort($kinds);

        return $kinds;
    }
}
