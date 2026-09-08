<?php

namespace App\Vat\Exceptions;

use RuntimeException;

/**
 * VAT refuses to proceed. Every case is one where guessing would produce a
 * confident wrong figure on a filed return.
 */
class VatException extends RuntimeException
{
    public static function noRateSchedule(string $period): self
    {
        return new self(
            "No VAT rate schedule covers {$period}. Returns will not be built against guessed rates — "
            .'add the schedule for this period with `php artisan vat:schedule`.'
        );
    }

    public static function ratesNotVerified(string $period): self
    {
        return new self(
            "The VAT rate schedule covering {$period} has not been verified against an official "
            .'source. Draft returns are allowed; finalising one is not. Verify it with '
            .'`php artisan vat:schedule --verify=<id> --by="Your name"`.'
        );
    }

    public static function alreadyFinalised(string $period): self
    {
        return new self(
            "The VAT return for {$period} is finalised and cannot be changed. "
            .'Filed periods keep the rules and figures they were computed with.'
        );
    }

    public static function invalidQuarter(int $quarter): self
    {
        return new self("Quarter must be 1-4, got {$quarter}.");
    }

    public static function priorPeriodOpen(string $period, string $open): self
    {
        return new self(
            "Cannot finalise {$period} while {$open} is still a draft. VAT credit carries forward, "
            .'so periods have to be filed in order or the carried figure is wrong.'
        );
    }
}
