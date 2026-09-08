<?php

namespace App\Support;

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

    /**
     * Parse what a person typed into a salary field.
     *
     * Entirely string arithmetic — casting "1234.56" through a float and
     * multiplying by 100 lands on 123455.99999999999, and that becomes a
     * salary a cent light forever. Thousands separators and spaces are
     * tolerated because people paste from spreadsheets.
     *
     * More decimal places than the currency has is rejected rather than
     * rounded: "1200000.5" LBP is a typo, and silently dropping the .5 is how
     * you end up explaining a payslip.
     */
    public static function parse(string $input, Currency $currency): self
    {
        $clean = str_replace([',', ' ', "\u{00A0}"], '', trim($input));

        if ($clean === '' || ! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $clean, $m)) {
            throw new InvalidArgumentException("\"{$input}\" is not an amount.");
        }

        [$sign, $whole, $fraction] = [$m[1], $m[2], $m[3] ?? ''];
        $scale = $currency->scale();

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException(
                $scale === 0
                    ? "{$currency->value} amounts are whole numbers."
                    : "{$currency->value} amounts take at most {$scale} decimal places."
            );
        }

        $minor = (int) ($whole.str_pad($fraction, $scale, '0'));

        return new self($sign === '-' ? -$minor : $minor, $currency);
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
     * Scale by an exact ratio, rounding ONCE.
     *
     * `percentBps` applied twice rounds twice and compounds the error. An
     * end-of-service indemnity is `average x coefficient x years`, three terms
     * that have to be combined in one division or the person is paid a
     * slightly wrong number. This routes through the same single rounding
     * helper as everything else — it is not a second rounding site.
     */
    public function scaleBy(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Cannot scale by a zero denominator.');
        }

        if ($numerator < 0 || $denominator < 0) {
            throw new InvalidArgumentException('Scaling a money amount by a negative ratio is not meaningful here.');
        }

        return new self(self::divideRoundHalfUp($this->minorUnits * $numerator, $denominator), $this->currency);
    }

    /**
     * The tax portion of a TAX-INCLUSIVE amount.
     *
     * At 11%, the VAT inside a 111,000 gross is 11,000 — not 12,210. Taking
     * `percentBps` of a gross figure is the single most common VAT error, so
     * the two directions are deliberately different methods with different
     * names rather than one method and a flag.
     *
     *   vat = gross * bps / (10000 + bps)
     */
    public function taxWithinBps(int $bps): self
    {
        if ($bps < 0) {
            throw new InvalidArgumentException('Basis points cannot be negative.');
        }

        return new self(self::divideRoundHalfUp($this->minorUnits * $bps, 10_000 + $bps), $this->currency);
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
