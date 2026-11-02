<?php

use App\Models\Employee;
use App\Models\EosiAccrual;
use App\Models\User;
use App\Payroll\Eosi\AccrueEosi;
use App\Payroll\Eosi\EosiEstimate;
use App\Payroll\Eosi\EosiInput;
use App\Payroll\Eosi\EosiStrategy;
use App\Payroll\Eosi\LumpSumStrategy;
use App\Payroll\Eosi\PayElement;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Support\Carbon;

/*
 | End-of-service INDEMNITY — what the employee is owed. Not the same thing as
 | the 8.5% the employer pays into the fund, which PayrollRunTest covers.
 |
 | The coefficient table is from docs/knowledge-base.md §3.2, which §3.7 rates
 | HIGH confidence. Salary figures below are test fixtures.
 */

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'owner@acme.test', 'role' => 'owner', 'locale' => 'en']);
});

function eosiInput(int $serviceMonths, array $elements = [], ?Money $salary = null, ?int $enrolmentMonths = null): EosiInput
{
    return new EosiInput(
        baseSalary: $salary ?? Money::usd(1_000_00),
        serviceMonths: $serviceMonths,
        asOf: Carbon::parse('2026-09-01'),
        enrolmentMonths: $enrolmentMonths,
        payElements: $elements,
    );
}

function bonusOn(string $date, int $usd = 1_200_00): PayElement
{
    return new PayElement('bonus', Money::usd($usd), Carbon::parse($date));
}

function makeEmployee(array $attributes = []): Employee
{
    return Employee::create(array_merge([
        'name' => 'Nadia Khoury',
        'base_salary_minor' => 1_000_00,
        'base_salary_currency' => Currency::USD,
        'is_nssf_registered' => true,
        'started_on' => '2016-09-01',
    ], $attributes));
}

// -- The seniority coefficient boundaries ----------------------------------

it('steps the coefficient at exactly five, ten and twenty years', function () {
    // The boundaries are inclusive at the LOWER end: five years exactly is
    // 65%, not 50%. Getting this backwards costs a step on someone's payout.
    expect(LumpSumStrategy::coefficientBpsFor(4 * 12))->toBe(5_000)
        ->and(LumpSumStrategy::coefficientBpsFor(5 * 12))->toBe(6_500);

    expect(LumpSumStrategy::coefficientBpsFor(9 * 12))->toBe(6_500)
        ->and(LumpSumStrategy::coefficientBpsFor(10 * 12))->toBe(8_500);

    expect(LumpSumStrategy::coefficientBpsFor(19 * 12))->toBe(8_500)
        ->and(LumpSumStrategy::coefficientBpsFor(20 * 12))->toBe(10_000);
});

it('holds the coefficient across a band rather than sliding', function () {
    // One month before a boundary is still the lower band.
    expect(LumpSumStrategy::coefficientBpsFor(5 * 12 - 1))->toBe(5_000)
        ->and(LumpSumStrategy::coefficientBpsFor(10 * 12 - 1))->toBe(6_500)
        ->and(LumpSumStrategy::coefficientBpsFor(20 * 12 - 1))->toBe(8_500)
        // And 30 years is still 100%, not more.
        ->and(LumpSumStrategy::coefficientBpsFor(30 * 12))->toBe(10_000);

    expect(LumpSumStrategy::coefficientBpsFor(0))->toBe(5_000);
});

it('carries the boundary through a whole estimate, not just the lookup', function () {
    $strategy = new LumpSumStrategy;

    // 1,000/month, 4 years: 1000 x 50% x 4 = 2,000
    expect($strategy->estimate(eosiInput(4 * 12))->accrued->minorUnits)->toBe(2_000_00);

    // 1,000/month, 5 years: 1000 x 65% x 5 = 3,250
    expect($strategy->estimate(eosiInput(5 * 12))->accrued->minorUnits)->toBe(3_250_00);
});

// -- The worked example ----------------------------------------------------

it('computes the whole formula end to end', function () {
    /*
     * average taxable salary over the last 12 months
     *   x years of service
     *   x seniority coefficient
     *
     * 1,000.00 USD x 10 years x 85% = 8,500.00 USD
     */
    $estimate = (new LumpSumStrategy)->estimate(eosiInput(10 * 12));

    expect($estimate->accrued->minorUnits)->toBe(8_500_00)
        ->and($estimate->accrued->currency)->toBe(Currency::USD)
        ->and($estimate->coefficientBps)->toBe(8_500)
        ->and($estimate->serviceMonths)->toBe(120)
        ->and($estimate->serviceYears())->toBe(10)
        ->and($estimate->averageSalary->minorUnits)->toBe(1_000_00)
        ->and($estimate->coefficientPercent())->toBe('85%');
});

it('keeps the estimate in the currency the contract was agreed in', function () {
    $estimate = (new LumpSumStrategy)->estimate(
        eosiInput(10 * 12, salary: Money::lbp(90_000_000)),
    );

    // 90,000,000 LBP x 10 x 85% = 765,000,000 LBP. Never silently converted.
    expect($estimate->accrued->currency)->toBe(Currency::LBP)
        ->and($estimate->accrued->minorUnits)->toBe(765_000_000);
});

it('rounds the three terms once, not at each step', function () {
    // An odd salary over a partial year is where double rounding shows up.
    // 1,000.01 x (65% x 67/12) = 100001 x 6500 x 67 / 120000 = 362,919.72 -> 362,920
    $estimate = (new LumpSumStrategy)->estimate(
        eosiInput(67, salary: Money::usd(1_000_01)),
    );

    expect($estimate->accrued->minorUnits)->toBe(362_920);
});

// -- The three-year bonus carve-out ----------------------------------------

it('includes a bonus paid in each of the last three years', function () {
    $estimate = (new LumpSumStrategy)->estimate(eosiInput(10 * 12, [
        bonusOn('2026-06-01'),
        bonusOn('2025-06-01'),
        bonusOn('2024-06-01'),
    ]));

    // Only the bonus inside the last 12 months joins the average, spread
    // across those months: 1,000 + 1,200/12 = 1,100.
    expect($estimate->averageSalary->minorUnits)->toBe(1_100_00)
        // 1,100 x 10 x 85% = 9,350
        ->and($estimate->accrued->minorUnits)->toBe(9_350_00)
        ->and($estimate->inputs['qualifying_kinds'])->toBe(['bonus'])
        ->and($estimate->inputs['excluded_kinds'])->toBe([]);
});

it('excludes a bonus that skipped a year', function () {
    // Two years running is not "consistently for three years or more".
    $estimate = (new LumpSumStrategy)->estimate(eosiInput(10 * 12, [
        bonusOn('2026-06-01'),
        bonusOn('2025-06-01'),
    ]));

    expect($estimate->averageSalary->minorUnits)->toBe(1_000_00)
        ->and($estimate->accrued->minorUnits)->toBe(8_500_00)
        ->and($estimate->inputs['qualifying_kinds'])->toBe([])
        ->and($estimate->inputs['excluded_kinds'])->toBe(['bonus']);
});

it('excludes a kind with a gap in the middle, however long the history', function () {
    // Paid in years 1 and 3 but not year 2 — a gap disqualifies the kind
    // rather than counting the years around it.
    $estimate = (new LumpSumStrategy)->estimate(eosiInput(10 * 12, [
        bonusOn('2026-06-01'),
        bonusOn('2024-06-01'),
        bonusOn('2023-06-01'),
        bonusOn('2022-06-01'),
    ]));

    expect($estimate->inputs['qualifying_kinds'])->toBe([])
        ->and($estimate->accrued->minorUnits)->toBe(8_500_00);
});

it('judges bonus and overtime separately', function () {
    $estimate = (new LumpSumStrategy)->estimate(eosiInput(10 * 12, [
        bonusOn('2026-06-01'), bonusOn('2025-06-01'), bonusOn('2024-06-01'),
        new PayElement('overtime', Money::usd(600_00), Carbon::parse('2026-07-01')),
    ]));

    // The bonus qualifies on its own record; one month of overtime does not.
    expect($estimate->inputs['qualifying_kinds'])->toBe(['bonus'])
        ->and($estimate->inputs['excluded_kinds'])->toBe(['overtime'])
        ->and($estimate->averageSalary->minorUnits)->toBe(1_100_00);
});

it('refuses to fold an element paid in another currency into the average', function () {
    // A bonus paid in lira against a USD contract is a question for a person,
    // not something to convert at an assumed rate.
    expect(fn () => (new LumpSumStrategy)->estimate(eosiInput(10 * 12, [
        new PayElement('bonus', Money::lbp(1_000_000), Carbon::parse('2026-06-01')),
        new PayElement('bonus', Money::lbp(1_000_000), Carbon::parse('2025-06-01')),
        new PayElement('bonus', Money::lbp(1_000_000), Carbon::parse('2024-06-01')),
    ])))->toThrow(InvalidArgumentException::class);
});

// -- The strategy is swappable --------------------------------------------

it('lets a different strategy be swapped in without touching any caller', function () {
    // The direct mitigation for the Law 319 risk: a pension implementation
    // binds here and the accrual action, screen and command are untouched.
    app()->bind(EosiStrategy::class, fn () => new class implements EosiStrategy
    {
        public function name(): string
        {
            return 'monthly_pension';
        }

        public function version(): string
        {
            return '2027.1';
        }

        public function estimate(EosiInput $input): EosiEstimate
        {
            return new EosiEstimate(
                accrued: Money::usd(42_00),
                averageSalary: $input->baseSalary,
                coefficientBps: 10_000,
                serviceMonths: $input->serviceMonths,
                enrolmentMonths: $input->coefficientMonths(),
                strategy: $this->name(),
                formulaVersion: $this->version(),
            );
        }
    });

    $this->tenant->run(function () {
        $employee = makeEmployee();
        $accrual = app(AccrueEosi::class)->accrue($employee, Carbon::parse('2026-09-01'));

        expect($accrual->accrued_minor)->toBe(42_00)
            ->and($accrual->strategy)->toBe('monthly_pension')
            ->and($accrual->formula_version)->toBe('2027.1');
    });
});

// -- The accrual ledger ----------------------------------------------------

it('stores the estimate with the formula that produced it', function () {
    $this->tenant->run(function () {
        $employee = makeEmployee();

        $accrual = app(AccrueEosi::class)->accrue($employee, Carbon::parse('2026-09-01'));

        expect($accrual->accrued_minor)->toBe(8_500_00)
            ->and($accrual->accrued_currency)->toBe(Currency::USD)
            ->and($accrual->coefficient_bps)->toBe(8_500)
            ->and($accrual->service_months)->toBe(120)
            ->and($accrual->strategy)->toBe('lump_sum')
            ->and($accrual->formula_version)->toBe('2026.1')
            // The working, so a departing employee can be shown how it was reached.
            ->and($accrual->inputs['consistency_years_required'])->toBe(3);
    });
});

it('corrects the same day rather than appending a second answer', function () {
    $this->tenant->run(function () {
        $employee = makeEmployee();
        $asOf = Carbon::parse('2026-09-01');

        app(AccrueEosi::class)->accrue($employee, $asOf);
        $employee->update(['base_salary_minor' => 2_000_00]);
        app(AccrueEosi::class)->accrue($employee, $asOf);

        expect(EosiAccrual::count())->toBe(1)
            ->and(EosiAccrual::sole()->accrued_minor)->toBe(17_000_00);
    });
});

it('keeps an older row saying what it said after the formula moves', function () {
    $this->tenant->run(function () {
        $employee = makeEmployee();

        $old = EosiAccrual::create([
            'employee_id' => $employee->id,
            'as_of' => '2025-09-01',
            'strategy' => 'lump_sum',
            'formula_version' => '2025.1',
            'service_months' => 108,
            'enrolment_months' => 108,
            'coefficient_bps' => 6_500,
            'average_salary_minor' => 1_000_00,
            'average_salary_currency' => Currency::USD,
            'accrued_minor' => 5_850_00,
            'accrued_currency' => Currency::USD,
        ]);

        // Recomputing today does not rewrite last year's statement.
        app(AccrueEosi::class)->accrue($employee, Carbon::parse('2026-09-01'));

        expect($old->fresh()->accrued_minor)->toBe(5_850_00)
            ->and($old->fresh()->matchesFormula('lump_sum', '2026.1'))->toBeFalse();
    });
});

it('skips someone with under a month of service', function () {
    $this->tenant->run(function () {
        makeEmployee(['started_on' => '2026-08-25']);

        [$accrued, $skipped] = app(AccrueEosi::class)->accrueAll(Carbon::parse('2026-09-01'));

        expect($accrued)->toBe(0)->and($skipped)->toBe(1)
            ->and(EosiAccrual::count())->toBe(0);
    });
});

// -- Screens ---------------------------------------------------------------

function asEosiUser(string $role = 'owner'): User
{
    tenancy()->initialize(test()->tenant);

    $user = $role === 'owner'
        ? User::where('role', 'owner')->sole()
        : User::create([
            'name' => ucfirst($role), 'email' => $role.'@acme.test',
            'password' => 'password-12345', 'role' => $role, 'locale' => 'en',
        ]);

    test()->actingAs($user);

    return $user;
}

it('shows the estimate and its working to any signed-in user', function () {
    // The screen values service as of today, and makeEmployee() starts on
    // 2016-09-01: 8,500.00 is exactly 120 months. Unfrozen, this went red in
    // October 2026 when the real clock reached 121.
    $this->travelTo(Carbon::parse('2026-09-08'));
    tenancy()->initialize($this->tenant);
    $employee = makeEmployee();
    asEosiUser('staff');

    $this->get(tenantUrl('acme', "/employees/{$employee->id}/eosi"))
        ->assertOk()
        ->assertSee('Nadia Khoury')
        ->assertSee('8,500.00')
        ->assertSee('85%')
        ->assertSee('lump_sum');
});

it('does not let staff record an estimate or add a bonus', function () {
    tenancy()->initialize($this->tenant);
    $employee = makeEmployee();
    asEosiUser('staff');

    $this->post(tenantUrl('acme', "/employees/{$employee->id}/eosi"))->assertForbidden();

    $this->post(tenantUrl('acme', "/employees/{$employee->id}/pay-elements"), [
        'kind' => 'bonus', 'amount' => '500', 'paid_on' => '2026-06-01',
    ])->assertForbidden();

    tenancy()->initialize($this->tenant);
    expect(EosiAccrual::count())->toBe(0);
});

it('records a bonus in the contract currency, in minor units', function () {
    tenancy()->initialize($this->tenant);
    $employee = makeEmployee();
    asEosiUser();

    $this->post(tenantUrl('acme', "/employees/{$employee->id}/pay-elements"), [
        'kind' => 'bonus', 'amount' => '1200.50', 'paid_on' => '2026-06-01',
    ])->assertRedirect();

    tenancy()->initialize($this->tenant);
    $element = $employee->payElements()->sole();

    expect($element->amount_minor)->toBe(1_200_50)
        ->and($element->amount_currency)->toBe(Currency::USD);
});

it('accrues for a whole business from the command', function () {
    tenancy()->initialize($this->tenant);
    makeEmployee();
    makeEmployee(['name' => 'Karim Aoun', 'started_on' => '2020-01-01']);
    tenancy()->end();

    $this->artisan('eosi:accrue', ['tenant' => 'acme', '--date' => '2026-09-01'])
        ->assertSuccessful();

    $this->tenant->run(fn () => expect(EosiAccrual::count())->toBe(2));
});

// -- Enrolment is a different clock from service ---------------------------

it('chooses the coefficient by ENROLMENT years, not service years', function () {
    // Ten years on the job but only declared four years ago: the band comes
    // from enrolment (50%), while the multiplier is still ten years of
    // service. Conflating them would pay this person 85% of ten years.
    $estimate = (new LumpSumStrategy)->estimate(eosiInput(10 * 12, enrolmentMonths: 4 * 12));

    expect($estimate->coefficientBps)->toBe(5_000)
        ->and($estimate->serviceMonths)->toBe(120)
        ->and($estimate->enrolmentMonths)->toBe(48)
        // 1,000 x 10 years x 50% = 5,000, not 8,500.
        ->and($estimate->accrued->minorUnits)->toBe(5_000_00);
});

it('treats a missing enrolment date as enrolled from the start date', function () {
    $estimate = (new LumpSumStrategy)->estimate(eosiInput(10 * 12));

    expect($estimate->enrolmentMonths)->toBe(120)
        ->and($estimate->coefficientBps)->toBe(8_500)
        // And says so on the row, because it decides someone's payment.
        ->and($estimate->inputs['enrolment_assumed_from_start_date'])->toBeTrue();
});

it('reads the enrolment date off the employee', function () {
    $this->tenant->run(function () {
        $employee = makeEmployee([
            'started_on' => '2016-09-01',
            'nssf_enrolled_on' => '2022-09-01',
        ]);

        $accrual = app(AccrueEosi::class)->accrue($employee, Carbon::parse('2026-09-01'));

        expect($accrual->service_months)->toBe(120)
            ->and($accrual->enrolment_months)->toBe(48)
            ->and($accrual->coefficient_bps)->toBe(5_000)
            ->and($accrual->inputs['enrolment_assumed_from_start_date'])->toBeFalse();
    });
});

it('gives an undeclared employee no enrolment years', function () {
    $this->tenant->run(function () {
        $employee = makeEmployee(['is_nssf_registered' => false]);

        $accrual = app(AccrueEosi::class)->accrue($employee, Carbon::parse('2026-09-01'));

        // Ten years of service, no NSSF enrolment: lowest band. The service
        // multiplier is untouched — the employer still owes those years.
        expect($accrual->enrolment_months)->toBe(0)
            ->and($accrual->coefficient_bps)->toBe(5_000)
            ->and($accrual->service_months)->toBe(120)
            ->and($accrual->accrued_minor)->toBe(5_000_00);
    });
});

it('refuses an enrolment date before the start date', function () {
    tenancy()->initialize(test()->tenant);
    $employee = makeEmployee();
    asEosiUser();

    $this->from(tenantUrl('acme', "/employees/{$employee->id}"))
        ->put(tenantUrl('acme', "/employees/{$employee->id}"), [
            'name' => 'Nadia Khoury',
            'base_salary' => '1000',
            'base_salary_currency' => 'USD',
            'started_on' => '2016-09-01',
            'nssf_enrolled_on' => '2015-01-01',
        ])
        ->assertInvalid(['nssf_enrolled_on']);
});
