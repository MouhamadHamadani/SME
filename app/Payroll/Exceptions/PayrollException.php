<?php

namespace App\Payroll\Exceptions;

use RuntimeException;

/**
 * Payroll refuses to proceed.
 *
 * Every one of these is a case where guessing would produce a confident wrong
 * number — an absent rate schedule, unverified rates, a finalised run being
 * edited. Failing loudly is the correct behaviour for a compliance product.
 */
class PayrollException extends RuntimeException
{
    public static function noRateSchedule(string $period): self
    {
        return new self(
            "No NSSF rate schedule covers {$period}. Payroll will not run against guessed rates — "
            .'add the schedule for this period with `php artisan nssf:schedule`.'
        );
    }

    public static function ratesNotVerified(string $period): self
    {
        return new self(
            "The NSSF rate schedule covering {$period} has not been verified against an official "
            .'decree. Draft runs are allowed; finalising one is not. Verify it with '
            .'`php artisan nssf:schedule --verify=<id> --by="Your name"`.'
        );
    }

    public static function alreadyFinalised(string $period): self
    {
        return new self(
            "The payroll run for {$period} is finalised and cannot be changed. "
            .'Finalised periods keep the rules and figures they were computed with.'
        );
    }

    public static function noEmployees(string $period): self
    {
        return new self("There are no active employees for {$period}.");
    }
}
