<?php

namespace App\Payroll\Eosi;

/**
 * How end-of-service is calculated.
 *
 * THIS INTERFACE IS THE POINT, not polish. Law 319 could move Lebanese EOSI
 * from the current lump-sum model to a monthly pension (§3.2, §11). When that
 * happens, a pension implementation is a new class behind this interface and
 * every caller — the accrual action, the screen, the tests — keeps working.
 *
 * The contract that makes that swap safe:
 *
 *   - `estimate()` is PURE. No database, no clock, no config. The same input
 *     produces the same number today and in five years, which is what lets a
 *     historical estimate be reproduced rather than recomputed under new law.
 *   - `name()` and `version()` are STORED on every accrual row. A stored
 *     estimate says which law produced it; nothing infers that from the
 *     current binding.
 *   - Bump `version()` whenever the arithmetic changes, including a bug fix.
 *     Two rows claiming the same version must be reproducible from each other.
 */
interface EosiStrategy
{
    /** Stable identifier stored on the accrual row, e.g. "lump_sum". */
    public function name(): string;

    /** Bumped whenever the arithmetic changes, e.g. "2026.1". */
    public function version(): string;

    public function estimate(EosiInput $input): EosiEstimate;
}
