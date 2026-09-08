<?php

namespace App\Models;

use App\Support\Currency;
use App\Support\Money;
use App\Support\RateBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The two LBP/USD rates for one day. Central, shared by every business.
 *
 * The market rate and the MoF-prescribed rate are INDEPENDENT values. Neither
 * is derived from, defaults to, or falls back on the other — a day with only
 * a market rate means the prescribed rate is unknown, not that they are equal.
 * Guessing there would produce a filing figure nobody entered.
 */
class ExchangeRate extends Model
{
    protected $fillable = [
        'rate_date',
        'market_rate_lbp_per_usd',
        'mof_rate_lbp_per_usd',
        'source',
        'verified_at',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'market_rate_lbp_per_usd' => 'integer',
            'mof_rate_lbp_per_usd' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Pinned to the central connection — same rule as NssfRateSchedule.
     *
     * Tenancy repoints `database.default` at the tenant, and these rows live
     * centrally. Without this, a lookup during a payroll run would go looking
     * for `exchange_rates` inside the business's own database.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection', config('database.default'));
    }

    /** The row for an exact day, or null. */
    public static function forDate(Carbon $date): ?self
    {
        return static::query()->whereDate('rate_date', $date->toDateString())->first();
    }

    /**
     * The most recent row on or before a date.
     *
     * Rates are entered daily by hand, and weekends and holidays exist, so
     * "the rate on Sunday" legitimately means Friday's. Carrying the last
     * known rate forward is how the market itself is read; inventing one is
     * not, which is why there is no interpolation here.
     */
    public static function asOf(Carbon $date): ?self
    {
        return static::query()
            ->whereDate('rate_date', '<=', $date->toDateString())
            ->orderByDesc('rate_date')
            ->first();
    }

    /** Whole LBP per USD on this basis, or null if that rate was never entered. */
    public function rateFor(RateBasis $basis): ?int
    {
        return $this->{$basis->column()};
    }

    /**
     * The rate, or a refusal.
     *
     * The house rule everywhere else in this system: refuse rather than
     * compute from a guess. A payroll or a VAT figure converted at an invented
     * rate looks authoritative and gets declared.
     */
    public function requireRateFor(RateBasis $basis): int
    {
        $rate = $this->rateFor($basis);

        if ($rate === null || $rate <= 0) {
            throw new RuntimeException(sprintf(
                'No %s rate recorded for %s. Enter it under Settings → Exchange rates before continuing.',
                $basis->value,
                $this->rate_date->toDateString(),
            ));
        }

        if ($basis === RateBasis::Mof && ! $this->isVerified()) {
            throw new RuntimeException(sprintf(
                'The Ministry rate for %s has not been verified. Nothing may be filed against an unverified rate.',
                $this->rate_date->toDateString(),
            ));
        }

        return $rate;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasBoth(): bool
    {
        return $this->market_rate_lbp_per_usd !== null && $this->mof_rate_lbp_per_usd !== null;
    }

    /**
     * How far the prescribed rate sits from the market, in basis points.
     *
     * Positive means the market rate is HIGHER than the Ministry's — i.e. a
     * dollar reported at the prescribed rate understates what it is actually
     * worth. Null when either rate is missing, because a delta against an
     * unknown is not zero.
     */
    public function spreadBps(): ?int
    {
        if (! $this->hasBoth() || $this->mof_rate_lbp_per_usd === 0) {
            return null;
        }

        $difference = $this->market_rate_lbp_per_usd - $this->mof_rate_lbp_per_usd;

        return (int) round($difference * 10000 / $this->mof_rate_lbp_per_usd);
    }

    /** One USD valued in LBP on this basis — the conversion, done in Money. */
    public function convert(Money $usd, RateBasis $basis): Money
    {
        if ($usd->currency !== Currency::USD) {
            throw new RuntimeException('Only a USD amount is converted at an LBP/USD rate.');
        }

        return $usd->convertTo(Currency::LBP, $this->requireRateFor($basis));
    }
}
