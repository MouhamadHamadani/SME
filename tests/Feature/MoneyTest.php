<?php

use App\Support\Currency;
use App\Support\Money;

it('keeps LBP as whole lira and USD as cents', function () {
    expect(Money::lbp(44_750_000)->minorUnits)->toBe(44_750_000)
        ->and(Money::lbp(44_750_000)->format())->toBe('44,750,000')
        ->and(Money::usd(50_000)->format())->toBe('500.00')
        ->and(Money::usd(1)->format())->toBe('0.01')
        ->and(Money::usd(-12_345)->format())->toBe('-123.45');
});

it('refuses to add two different currencies', function () {
    Money::usd(100)->plus(Money::lbp(100));
})->throws(InvalidArgumentException::class, 'Cannot combine');

it('converts USD to LBP at the quoted rate', function () {
    // $500 at 89,500 LBP/USD = 44,750,000 LBP
    expect(Money::usd(50_000)->convertTo(Currency::LBP, 89_500)->minorUnits)
        ->toBe(44_750_000);
});

it('converts LBP back to USD', function () {
    expect(Money::lbp(44_750_000)->convertTo(Currency::USD, 89_500)->minorUnits)
        ->toBe(50_000);
});

it('rounds conversions half up rather than truncating', function () {
    // 1 lira at 3 LBP/USD = 33.33 cents -> 33
    expect(Money::lbp(1)->convertTo(Currency::USD, 3)->minorUnits)->toBe(33);
    // 2 lira at 3 = 66.67 cents -> 67, not 66
    expect(Money::lbp(2)->convertTo(Currency::USD, 3)->minorUnits)->toBe(67);
});

it('applies basis-point rates exactly', function () {
    // 8.5% of 1,000,000 = 85,000
    expect(Money::lbp(1_000_000)->percentBps(850)->minorUnits)->toBe(85_000);
    // 3% of 2,500,000 = 75,000
    expect(Money::lbp(2_500_000)->percentBps(300)->minorUnits)->toBe(75_000);
});

it('rounds a rate half up, never shaving a lira', function () {
    // 8.5% of 1 lira = 0.085 -> 0
    expect(Money::lbp(1)->percentBps(850)->minorUnits)->toBe(0);
    // 8.5% of 6 lira = 0.51 -> 1
    expect(Money::lbp(6)->percentBps(850)->minorUnits)->toBe(1);
    // 50% of 5 -> 2.5 -> 3 (half up, not banker's rounding)
    expect(Money::lbp(5)->percentBps(5000)->minorUnits)->toBe(3);
});

it('does not drift on a realistic salary, unlike float maths', function () {
    // The float version of this is 4_050_000.0000000005
    $contribution = Money::lbp(45_000_000)->percentBps(900);

    expect($contribution->minorUnits)->toBe(4_050_000)
        ->and($contribution->minorUnits)->toBeInt();
});

it('caps at a ceiling with min()', function () {
    expect(Money::lbp(45_000_000)->min(Money::lbp(2_500_000))->minorUnits)->toBe(2_500_000)
        ->and(Money::lbp(1_000_000)->min(Money::lbp(2_500_000))->minorUnits)->toBe(1_000_000);
});

it('rejects a zero or negative exchange rate', function () {
    Money::usd(100)->convertTo(Currency::LBP, 0);
})->throws(InvalidArgumentException::class, 'greater than zero');
