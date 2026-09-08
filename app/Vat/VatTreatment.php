<?php

namespace App\Vat;

/**
 * How a transaction is treated for VAT.
 *
 * The distinction between zero-rated and exempt is the one that catches
 * people out. Both charge nothing to the customer, but:
 *
 *   ZERO-RATED  is taxable at 0%. It counts toward taxable turnover, and the
 *               input VAT on related purchases IS recoverable. Exports.
 *   EXEMPT      is outside the tax. It does NOT count toward taxable turnover,
 *               and input VAT on related purchases is NOT recoverable.
 *               Healthcare, education, most financial services.
 *
 * Treating an exempt supply as zero-rated overstates taxable turnover and
 * over-recovers input VAT — a real assessment risk, not a rounding nit.
 */
enum VatTreatment: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';

    /** Does VAT get charged at all? */
    public function isTaxable(): bool
    {
        return $this !== self::Exempt;
    }

    /** Does this count toward taxable turnover on the return? */
    public function countsTowardTaxableTurnover(): bool
    {
        return $this !== self::Exempt;
    }

    /** The rate to apply, in basis points, given the standard rate. */
    public function rateBps(int $standardBps): int
    {
        return match ($this) {
            self::Standard => $standardBps,
            self::ZeroRated, self::Exempt => 0,
        };
    }
}
