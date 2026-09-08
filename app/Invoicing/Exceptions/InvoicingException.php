<?php

namespace App\Invoicing\Exceptions;

use RuntimeException;

class InvoicingException extends RuntimeException
{
    public static function noVatSchedule(string $date): self
    {
        return new self(
            "No VAT rate schedule covers {$date}. An invoice cannot be issued against a guessed rate — "
            .'add the schedule with `php artisan vat:schedule`.'
        );
    }

    public static function notEditable(string $label): self
    {
        return new self(
            "Invoice {$label} has been issued and cannot be changed. Void it or raise a credit note; "
            .'the customer may already have reclaimed VAT against these figures.'
        );
    }

    public static function noLines(): self
    {
        return new self('An invoice needs at least one line before it can be issued.');
    }

    public static function missingExchangeRate(): self
    {
        return new self(
            'A USD invoice needs the LBP/USD rate on the day it is issued — VAT is declared in lira '
            .'and the figure has to be reproducible later.'
        );
    }

    public static function cannotVoidDraft(): self
    {
        return new self('A draft was never issued, so there is nothing to void. Delete it instead.');
    }

    public static function alreadyVoid(string $label): self
    {
        return new self("Invoice {$label} is already void.");
    }

    public static function cannotMarkPaid(string $label): self
    {
        return new self("Invoice {$label} is not issued, so it cannot be marked paid.");
    }

    public static function vatAlreadyFiled(string $label): self
    {
        return new self(
            "Invoice {$label} is already included in a filed VAT return and cannot be voided. "
            .'Raise a credit note in the current period instead.'
        );
    }
}
