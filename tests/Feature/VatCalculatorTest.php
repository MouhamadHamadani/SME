<?php

use App\Support\Currency;
use App\Support\Money;
use App\Vat\VatCalculator;
use App\Vat\VatTreatment;

/*
 | Worked examples at a FIXTURE rate of 11% (1100 bps).
 |
 | The rate is a test fixture, not a claim about what Lebanese VAT currently
 | is — that is data the system is told. These prove the engine: net vs gross,
 | the zero-rated/exempt distinction, and exact rounding. They stay valid
 | whatever rate a real schedule carries.
 */

const BPS = 1100;

beforeEach(function () {
    $this->calc = new VatCalculator;
});

it('adds VAT on top of a net amount', function () {
    // 11% of 1,000,000 = 110,000
    $a = $this->calc->fromNet(Money::lbp(1_000_000), VatTreatment::Standard, BPS);

    expect($a->net->minorUnits)->toBe(1_000_000)
        ->and($a->vat->minorUnits)->toBe(110_000)
        ->and($a->gross()->minorUnits)->toBe(1_110_000);
});

it('extracts VAT from within a tax-inclusive amount', function () {
    // The VAT inside 1,110,000 is 110,000 — NOT 11% of 1,110,000 (122,100).
    // This is the single most common VAT error.
    $a = $this->calc->fromGross(Money::lbp(1_110_000), VatTreatment::Standard, BPS);

    expect($a->vat->minorUnits)->toBe(110_000)
        ->and($a->net->minorUnits)->toBe(1_000_000)
        ->and($a->vat->minorUnits)->not->toBe(122_100);
});

it('round-trips net to gross and back', function () {
    foreach ([1, 999, 1_000_000, 7_777_777, 1_234_567_891] as $net) {
        $up = $this->calc->fromNet(Money::lbp($net), VatTreatment::Standard, BPS);
        $down = $this->calc->fromGross($up->gross(), VatTreatment::Standard, BPS);

        // Rounding may move a single lira on adversarial inputs; more than
        // that means the two directions disagree about the same rate.
        expect(abs($down->net->minorUnits - $net))->toBeLessThanOrEqual(1);
    }
});

it('keeps net plus VAT equal to gross, always', function () {
    foreach ([0, 1, 3, 12_345, 999_999_999] as $amount) {
        $fromNet = $this->calc->fromNet(Money::lbp($amount), VatTreatment::Standard, BPS);
        expect($fromNet->gross()->minorUnits)
            ->toBe($fromNet->net->minorUnits + $fromNet->vat->minorUnits);

        $fromGross = $this->calc->fromGross(Money::lbp($amount), VatTreatment::Standard, BPS);
        expect($fromGross->gross()->minorUnits)->toBe($amount);
    }
});

it('charges nothing on a zero-rated supply but still counts it as turnover', function () {
    // Exports: taxable at 0%. Input VAT on related purchases stays recoverable.
    $a = $this->calc->fromNet(Money::lbp(5_000_000), VatTreatment::ZeroRated, BPS);

    expect($a->vat->isZero())->toBeTrue()
        ->and($a->net->minorUnits)->toBe(5_000_000)
        ->and($a->countsTowardTaxableTurnover())->toBeTrue();
});

it('keeps an exempt supply out of taxable turnover entirely', function () {
    // Healthcare, education, most financial services. Charging nothing is the
    // same as zero-rated; the difference is that this is outside the tax, so
    // it must NOT inflate taxable turnover.
    $a = $this->calc->fromNet(Money::lbp(5_000_000), VatTreatment::Exempt, BPS);

    expect($a->vat->isZero())->toBeTrue()
        ->and($a->countsTowardTaxableTurnover())->toBeFalse();
});

it('rounds half up rather than truncating', function () {
    // 11% of 5 = 0.55 -> 1
    expect($this->calc->fromNet(Money::lbp(5), VatTreatment::Standard, BPS)->vat->minorUnits)->toBe(1);
    // 11% of 4 = 0.44 -> 0
    expect($this->calc->fromNet(Money::lbp(4), VatTreatment::Standard, BPS)->vat->minorUnits)->toBe(0);
});

it('handles a zero amount', function () {
    $a = $this->calc->fromNet(Money::lbp(0), VatTreatment::Standard, BPS);

    expect($a->vat->isZero())->toBeTrue()->and($a->gross()->isZero())->toBeTrue();
});

it('refuses to compute in USD', function () {
    // VAT is declared in lira; conversion is the caller's job so the rate used
    // is stored and auditable rather than implicit.
    $this->calc->fromNet(Money::usd(50_000), VatTreatment::Standard, BPS);
})->throws(InvalidArgumentException::class, 'declared in LBP');

it('refuses a negative amount', function () {
    $this->calc->fromNet(Money::of(-1, Currency::LBP), VatTreatment::Standard, BPS);
})->throws(InvalidArgumentException::class, 'cannot be negative');

it('follows whatever rate it is given, with nothing hard-coded', function () {
    // If the standard rate ever moves, only the schedule changes.
    expect($this->calc->fromNet(Money::lbp(1_000_000), VatTreatment::Standard, 1500)->vat->minorUnits)
        ->toBe(150_000)
        ->and($this->calc->fromGross(Money::lbp(1_150_000), VatTreatment::Standard, 1500)->vat->minorUnits)
        ->toBe(150_000);
});
