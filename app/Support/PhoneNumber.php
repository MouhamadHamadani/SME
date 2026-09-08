<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A phone number in the form WhatsApp wants: E.164 digits, no leading plus.
 *
 * Lebanese numbers are written half a dozen ways — "03 123 456", "+961 3 123
 * 456", "0096171234567", "71 234 567" — and Meta rejects all of them. Getting
 * this wrong means an invoice silently never arrives, so normalisation is one
 * function with tests rather than a regex at each call site.
 */
final readonly class PhoneNumber
{
    /** Lebanon. Numbers entered without a country code are assumed local. */
    public const DEFAULT_COUNTRY_CODE = '961';

    private function __construct(public string $e164) {}

    public static function parse(string $input, string $countryCode = self::DEFAULT_COUNTRY_CODE): self
    {
        $trimmed = trim($input);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('A phone number is required.');
        }

        /*
         * "+" or "00" is the caller SAYING the number is international. That
         * signal is the only reliable way to tell "+33 6…" (France) from a
         * local number — without it, prepending 961 to a French number
         * produces 96133… and the message goes nowhere. Guessing by length
         * would be worse; the prefix is the answer.
         */
        $isInternational = str_starts_with($trimmed, '+') || str_starts_with($digits, '00');

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (! $isInternational) {
            $digits = str_starts_with($digits, '0')
                // A national number with the trunk prefix: 03… -> 9613…
                ? $countryCode.ltrim($digits, '0')
                // Already carries the country code, or is a bare local number.
                : (str_starts_with($digits, $countryCode) ? $digits : $countryCode.$digits);
        }

        // Loose bounds rather than a Lebanese-only rule: customers abroad are
        // normal, and a stricter check would reject valid numbers.
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw new InvalidArgumentException("\"{$input}\" is not a usable phone number.");
        }

        return new self($digits);
    }

    /** Null instead of throwing, for optional fields. */
    public static function tryParse(?string $input, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?self
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        try {
            return self::parse($input, $countryCode);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** As a person reads it back: +961 71 234 567. */
    public function forDisplay(): string
    {
        return '+'.$this->e164;
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }
}
