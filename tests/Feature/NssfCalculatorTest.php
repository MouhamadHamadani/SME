<?php

use App\Payroll\NssfCalculator;
use App\Payroll\NssfRates;
use App\Support\Currency;
use App\Support\Money;

/*
 | Worked examples with FIXTURE rates.
 |
 | The ceilings below are test fixtures, not a claim about what the NSSF
 | ceilings currently are — that is data the system is told, never something it
 | assumes. These tests prove the ENGINE is right: the ceilings apply to the
 | correct branches, end-of-service is uncapped, and the arithmetic is exact.
 | They stay valid whatever figures a real schedule carries.
 */

function testRates(int $sicknessCeiling = 2_500_000, int $familyCeiling = 1_500_000): NssfRates
{
    return new NssfRates(
        sicknessEmployerBps: 800,   // 8%
        sicknessEmployeeBps: 300,   // 3%
        sicknessCeilingLbp: $sicknessCeiling,
        familyEmployerBps: 600,     // 6%
        familyCeilingLbp: $familyCeiling,
        eosiEmployerBps: 850,       // 8.5%
    );
}

beforeEach(function () {
    $this->calc = new NssfCalculator;
});

it('computes all three branches for a salary under every ceiling', function () {
    // 1,000,000 LBP — below both ceilings, so every base is the full salary.
    $b = $this->calc->calculate(Money::lbp(1_000_000), testRates());

    expect($b->sicknessEmployer->minorUnits)->toBe(80_000)   // 8%
        ->and($b->sicknessEmployee->minorUnits)->toBe(30_000) // 3%
        ->and($b->familyEmployer->minorUnits)->toBe(60_000)   // 6%
        ->and($b->eosiEmployer->minorUnits)->toBe(85_000);    // 8.5%

    expect($b->employeeDeductions()->minorUnits)->toBe(30_000)
        ->and($b->employerContributions()->minorUnits)->toBe(225_000) // 80+60+85k
        ->and($b->net()->minorUnits)->toBe(970_000)
        ->and($b->totalEmployerCost()->minorUnits)->toBe(1_225_000)
        ->and($b->isCapped())->toBeFalse();
});

it('caps the sickness and family branches at their own separate ceilings', function () {
    // 5,000,000 — above both. The two ceilings are DIFFERENT amounts, and
    // using one for both is the easy bug this pins down.
    $b = $this->calc->calculate(Money::lbp(5_000_000), testRates());

    expect($b->sicknessBase->minorUnits)->toBe(2_500_000)
        ->and($b->familyBase->minorUnits)->toBe(1_500_000)
        ->and($b->sicknessEmployer->minorUnits)->toBe(200_000) // 8% of 2.5m
        ->and($b->sicknessEmployee->minorUnits)->toBe(75_000)  // 3% of 2.5m
        ->and($b->familyEmployer->minorUnits)->toBe(90_000)    // 6% of 1.5m
        ->and($b->isCapped())->toBeTrue();
});

it('never caps end-of-service — it is charged on the full salary', function () {
    // The classic Lebanese payroll bug: applying a ceiling to EOSI
    // under-contributes for every employee above it.
    $b = $this->calc->calculate(Money::lbp(5_000_000), testRates());

    expect($b->eosiBase->minorUnits)->toBe(5_000_000)
        ->and($b->eosiEmployer->minorUnits)->toBe(425_000); // 8.5% of the FULL 5m

    // Explicitly: EOSI is not the ceiling-capped figure.
    expect($b->eosiEmployer->minorUnits)->not->toBe(212_500); // 8.5% of 2.5m
});

it('scales end-of-service with salary while the capped branches stay flat', function () {
    $low = $this->calc->calculate(Money::lbp(3_000_000), testRates());
    $high = $this->calc->calculate(Money::lbp(30_000_000), testRates());

    // Capped branches identical...
    expect($high->sicknessEmployer->minorUnits)->toBe($low->sicknessEmployer->minorUnits)
        ->and($high->familyEmployer->minorUnits)->toBe($low->familyEmployer->minorUnits);

    // ...EOSI ten times larger.
    expect($high->eosiEmployer->minorUnits)->toBe($low->eosiEmployer->minorUnits * 10);
});

it('treats a salary exactly on the ceiling as uncapped', function () {
    $b = $this->calc->calculate(Money::lbp(2_500_000), testRates(familyCeiling: 2_500_000));

    expect($b->sicknessBase->minorUnits)->toBe(2_500_000)
        ->and($b->isCapped())->toBeFalse();
});

it('charges nothing for an undeclared employee but still reports the gross', function () {
    $b = $this->calc->calculateUnregistered(Money::lbp(3_000_000));

    expect($b->gross->minorUnits)->toBe(3_000_000)
        ->and($b->employeeDeductions()->isZero())->toBeTrue()
        ->and($b->employerContributions()->isZero())->toBeTrue()
        ->and($b->net()->minorUnits)->toBe(3_000_000)
        ->and($b->totalEmployerCost()->minorUnits)->toBe(3_000_000);
});

it('handles a zero salary without dividing by anything', function () {
    $b = $this->calc->calculate(Money::lbp(0), testRates());

    expect($b->employerContributions()->isZero())->toBeTrue()
        ->and($b->net()->isZero())->toBeTrue();
});

it('refuses to calculate in USD', function () {
    // NSSF is declared in lira. Converting is the caller's job, at the run's
    // stored rate, so the conversion is auditable rather than implicit.
    $this->calc->calculate(Money::usd(50_000), testRates());
})->throws(InvalidArgumentException::class, 'calculated in LBP');

it('refuses a negative salary', function () {
    $this->calc->calculate(Money::of(-1, Currency::LBP), testRates());
})->throws(InvalidArgumentException::class, 'cannot be negative');

it('is reproducible — the same inputs always give the same numbers', function () {
    $rates = testRates();
    $first = $this->calc->calculate(Money::lbp(7_777_777), $rates);
    $second = $this->calc->calculate(Money::lbp(7_777_777), $rates);

    expect($first->employerContributions()->equals($second->employerContributions()))->toBeTrue();
});

it('keeps the books balanced: gross + employer = total cost, gross - employee = net', function () {
    foreach ([0, 1, 999_999, 2_500_000, 45_000_000, 1_234_567_891] as $salary) {
        $b = $this->calc->calculate(Money::lbp($salary), testRates());

        expect($b->totalEmployerCost()->minorUnits)
            ->toBe($b->gross->minorUnits + $b->employerContributions()->minorUnits)
            ->and($b->net()->minorUnits)
            ->toBe($b->gross->minorUnits - $b->employeeDeductions()->minorUnits);
    }
});
