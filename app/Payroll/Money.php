<?php

namespace App\Payroll;

use InvalidArgumentException;

/**
 * An exact amount of money, held as an integer number of minor units.
 *
 * Floats are not allowed anywhere near payroll: 0.1 + 0.2 !== 0.3, and a
 * fraction of a lira lost per employee per month becomes a reconciliation
 * argument with the NSSF. Every operation here is integer arithmetic.
 *
 * All rounding is half-up, applied once at the point a result is produced.
 */
final readonly class Money
{
    private function __construct(
        public int $minorUnits,
        public Currency $currency,
    ) {}

    public static function of(int $minorUnits, Currency $currency): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public static function lbp(int $lira): self
    {
        return new self($lira, Currency::LBP);
    }

    public static function usd(int $cents): self
    {
        return new self($cents, Currency::USD);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /** The smaller of the two — used to cap a salary at an NSSF ceiling. */
    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <= $other->minorUnits ? $this : $other;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    /**
     * Apply a rate given in basis points (1 bps = 0.01%), so 850 is 8.5%.
     *
     * Rates are stored as integer basis points rather than decimals precisely
     * so this stays exact: 8.5% of an odd lira amount has one unambiguous
     * answer instead of a float that depends on how it was written down.
     */
    public function percentBps(int $bps): self
    {
        if ($bps < 0) {
            throw new InvalidArgumentException('Basis points cannot be negative.');
        }

        return new self(self::divideRoundHalfUp($this->minorUnits * $bps, 10_000), $this->currency);
    }

    /**
     * Convert using an exchange rate expressed as whole LBP per 1 USD
     * (e.g. 89500). That is how the rate is quoted in Lebanon, and holding it
     * as an integer keeps the conversion reproducible for an audit years later.
     */
    public function convertTo(Currency $target, int $lbpPerUsd): self
    {
        if ($lbpPerUsd <= 0) {
            throw new InvalidArgumentException('The LBP/USD rate must be greater than zero.');
        }

        if ($this->currency === $target) {
            return $this;
        }

        return match ([$this->currency, $target]) {
            // cents -> lira: cents * rate / 100
            [Currency::USD, Currency::LBP] => new self(
                self::divideRoundHalfUp($this->minorUnits * $lbpPerUsd, 100),
                Currency::LBP,
            ),
            // lira -> cents: lira * 100 / rate
            [Currency::LBP, Currency::USD] => new self(
                self::divideRoundHalfUp($this->minorUnits * 100, $lbpPerUsd),
                Currency::USD,
            ),
        };
    }

    /** Human-readable, e.g. "44,750,000" or "500.00". Currency symbol is the caller's job. */
    public function format(): string
    {
        $scale = $this->currency->scale();
        $negative = $this->minorUnits < 0;
        $abs = abs($this->minorUnits);

        if ($scale === 0) {
            return ($negative ? '-' : '').number_format($abs);
        }

        $per = $this->currency->minorUnitsPerMajor();

        return ($negative ? '-' : '').number_format(intdiv($abs, $per)).'.'
            .str_pad((string) ($abs % $per), $scale, '0', STR_PAD_LEFT);
    }

    /**
     * Integer division rounding half away from zero.
     *
     * intdiv() truncates toward zero, which would quietly shave a lira off
     * every contribution. This is the only place rounding happens.
     */
    private static function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;
        $n = abs($numerator);
        $d = abs($denominator);

        return $sign * intdiv(2 * $n + $d, 2 * $d);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency->value} with {$other->currency->value}."
            );
        }
    }
}
