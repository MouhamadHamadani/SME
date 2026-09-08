<?php

namespace App\Vat;

use App\Support\Money;

/**
 * One transaction split into its net and VAT parts.
 *
 * `net + vat === gross` always holds, because gross is derived rather than
 * stored alongside — there is no third number that can disagree with the
 * other two.
 */
final readonly class VatAmount
{
    public function __construct(
        public Money $net,
        public Money $vat,
        public VatTreatment $treatment,
    ) {}

    public function gross(): Money
    {
        return $this->net->plus($this->vat);
    }

    /** Exempt supplies are outside the tax and stay out of taxable turnover. */
    public function countsTowardTaxableTurnover(): bool
    {
        return $this->treatment->countsTowardTaxableTurnover();
    }
}
