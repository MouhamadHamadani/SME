<?php

namespace App\Vat;

use App\Support\Currency;
use App\Support\Money;
use InvalidArgumentException;

/**
 * VAT on one transaction line.
 *
 * Pure: rate in, numbers out. No database, no clock, no assumption about what
 * the rate is — the caller supplies it from an effective-dated schedule, the
 * same way payroll does.
 *
 * Lebanese invoices are quoted both ways in practice — some businesses price
 * net and add VAT, others quote a VAT-inclusive shelf price — so both
 * directions are supported explicitly. They are separate methods on purpose:
 * applying the rate to a gross figure instead of extracting it from it is the
 * classic VAT error, and it inflates every line.
 */
final class VatCalculator
{
    /** Amount is NET (VAT exclusive): VAT is added on top. */
    public function fromNet(Money $net, VatTreatment $treatment, int $standardBps): VatAmount
    {
        $this->guard($net);

        $vat = $net->percentBps($treatment->rateBps($standardBps));

        return new VatAmount($net, $vat, $treatment);
    }

    /** Amount is GROSS (VAT inclusive): VAT is extracted from within it. */
    public function fromGross(Money $gross, VatTreatment $treatment, int $standardBps): VatAmount
    {
        $this->guard($gross);

        $vat = $gross->taxWithinBps($treatment->rateBps($standardBps));

        return new VatAmount($gross->minus($vat), $vat, $treatment);
    }

    private function guard(Money $amount): void
    {
        if ($amount->currency !== Currency::LBP) {
            throw new InvalidArgumentException(
                'VAT is declared in LBP. Convert at the period\'s exchange rate first.'
            );
        }

        if ($amount->minorUnits < 0) {
            throw new InvalidArgumentException('A VAT amount cannot be negative. Record a credit note instead.');
        }
    }
}
