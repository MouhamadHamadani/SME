<?php

namespace App\Support;

/**
 * WHICH LBP/USD rate, and therefore for what purpose.
 *
 * This exists so the §3.6 rule is expressible in code rather than in a comment.
 * Lebanon prescribes one rate for tax filings and the market quotes another;
 * a figure converted at the wrong one is not slightly off, it is a misstated
 * declaration.
 *
 * `ExchangeRate::rateFor()` takes one of these and there is no other accessor,
 * so every conversion has to say out loud which rate it is using.
 */
enum RateBasis: string
{
    /** What the market says. The business's own books and internal reporting. */
    case Market = 'market';

    /** What the Ministry prescribes. Anything destined for a filing. */
    case Mof = 'mof';

    /** The column holding this rate on `exchange_rates`. */
    public function column(): string
    {
        return match ($this) {
            self::Market => 'market_rate_lbp_per_usd',
            self::Mof => 'mof_rate_lbp_per_usd',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Market => __('app.rates.basis_market'),
            self::Mof => __('app.rates.basis_mof'),
        };
    }
}
