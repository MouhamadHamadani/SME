<?php

namespace App\Support;

/**
 * The two currencies this product books in.
 *
 * `scale` is how many decimal places the currency has, which decides what one
 * minor unit means. USD has cents (scale 2). LBP has piastres on paper, but
 * they have been meaningless for decades and no Lebanese business books them —
 * so LBP is scale 0 and one minor unit is one lira.
 */
enum Currency: string
{
    case USD = 'USD';
    case LBP = 'LBP';

    public function scale(): int
    {
        return match ($this) {
            self::USD => 2,
            self::LBP => 0,
        };
    }

    /** Number of minor units in one major unit: 100 for USD, 1 for LBP. */
    public function minorUnitsPerMajor(): int
    {
        return 10 ** $this->scale();
    }
}
