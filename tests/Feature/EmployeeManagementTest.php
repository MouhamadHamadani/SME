<?php

use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Payroll\RunPayroll;
use App\Support\Currency;
use App\Support\Money;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'owner@acme.test', 'role' => 'owner', 'locale' => 'en']);
});

/** Act as a user of this tenant with the given role. */
function asRole(string $role): User
{
    tenancy()->initialize(test()->tenant);

    $user = $role === 'owner'
        ? User::where('role', 'owner')->sole()
        : User::create([
            'name' => ucfirst($role),
            'email' => $role.'@acme.test',
            'password' => 'password-12345',
            'role' => $role,
            'locale' => 'en',
        ]);

    test()->actingAs($user);

    return $user;
}

function employeePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Rima Khoury',
        'nssf_number' => '482100',
        'base_salary' => '500.00',
        'base_salary_currency' => 'USD',
        'has_dependent_spouse' => '0',
        'dependent_children' => '0',
        'is_nssf_registered' => '1',
        'started_on' => '2024-04-01',
        'ended_on' => '',
    ], $overrides);
}

// -- Salary parsing ---------------------------------------------------------

it('parses a typed salary into exact minor units', function () {
    expect(Money::parse('500.00', Currency::USD)->minorUnits)->toBe(50_000)
        ->and(Money::parse('500', Currency::USD)->minorUnits)->toBe(50_000)
        ->and(Money::parse('1234.56', Currency::USD)->minorUnits)->toBe(123_456)
        ->and(Money::parse('1,200,000', Currency::LBP)->minorUnits)->toBe(1_200_000)
        ->and(Money::parse(' 1 200 000 ', Currency::LBP)->minorUnits)->toBe(1_200_000);
});

it('parses without float drift', function () {
    // (int) (1234.56 * 100) is 123455 — a cent light, forever.
    expect(Money::parse('1234.56', Currency::USD)->minorUnits)->toBe(123_456)
        ->and(Money::parse('0.07', Currency::USD)->minorUnits)->toBe(7)
        ->and(Money::parse('8.29', Currency::USD)->minorUnits)->toBe(829);
});

it('rejects more decimals than the currency has, rather than rounding them away', function () {
    expect(fn () => Money::parse('1200000.5', Currency::LBP))
        ->toThrow(InvalidArgumentException::class, 'whole numbers');

    expect(fn () => Money::parse('10.005', Currency::USD))
        ->toThrow(InvalidArgumentException::class, '2 decimal places');
});

it('rejects text that is not an amount', function () {
    foreach (['', 'abc', '12.', '1.2.3', '$500'] as $bad) {
        expect(fn () => Money::parse($bad, Currency::USD))->toThrow(InvalidArgumentException::class);
    }
});

// -- Authorization ----------------------------------------------------------

it('lets an owner add an employee', function () {
    asRole('owner');

    $this->post(tenantUrl('acme', '/employees'), employeePayload())
        ->assertRedirect(tenantUrl('acme', '/employees'));

    tenancy()->initialize($this->tenant);
    $employee = Employee::sole();

    expect($employee->name)->toBe('Rima Khoury')
        ->and($employee->base_salary_minor)->toBe(50_000)
        ->and($employee->base_salary_currency)->toBe(Currency::USD)
        ->and($employee->is_nssf_registered)->toBeTrue();
});

it('lets an accountant manage payroll — that is what the seat is for', function () {
    asRole('accountant');

    $this->post(tenantUrl('acme', '/employees'), employeePayload())->assertRedirect();

    tenancy()->initialize($this->tenant);
    expect(Employee::count())->toBe(1);
});

it('does not let staff change salaries', function () {
    asRole('staff');

    $this->post(tenantUrl('acme', '/employees'), employeePayload())->assertForbidden();
    $this->get(tenantUrl('acme', '/employees/new'))->assertForbidden();

    tenancy()->initialize($this->tenant);
    expect(Employee::count())->toBe(0);
});

it('still lets staff read the employee list', function () {
    $this->tenant->run(fn () => Employee::factory()->create(['name' => 'Karim Aoun']));
    asRole('staff');

    $this->get(tenantUrl('acme', '/employees'))
        ->assertOk()
        ->assertSee('Karim Aoun')
        // No edit affordance for someone who cannot edit.
        ->assertDontSee('Add employee');
});

it('does not let staff draft a payroll run', function () {
    schedule();
    $this->tenant->run(fn () => Employee::factory()->create());
    asRole('staff');

    $this->post(tenantUrl('acme', '/payroll'), [
        'period_year' => 2026, 'period_month' => 3, 'lbp_per_usd' => 89_500,
    ])->assertForbidden();
});

// -- Validation -------------------------------------------------------------

it('rejects a salary that is not a number', function () {
    asRole('owner');

    $this->from(tenantUrl('acme', '/employees/new'))
        ->post(tenantUrl('acme', '/employees'), employeePayload(['base_salary' => 'about five hundred']))
        ->assertInvalid(['base_salary']);

    tenancy()->initialize($this->tenant);
    expect(Employee::count())->toBe(0);
});

it('rejects a zero salary', function () {
    asRole('owner');

    $this->from(tenantUrl('acme', '/employees/new'))
        ->post(tenantUrl('acme', '/employees'), employeePayload(['base_salary' => '0']))
        ->assertInvalid(['base_salary' => 'more than zero']);
});

it('rejects fractional lira', function () {
    asRole('owner');

    $this->from(tenantUrl('acme', '/employees/new'))
        ->post(tenantUrl('acme', '/employees'), employeePayload([
            'base_salary' => '1200000.75', 'base_salary_currency' => 'LBP',
        ]))
        ->assertInvalid(['base_salary']);
});

it('rejects an end date before the start date', function () {
    asRole('owner');

    $this->from(tenantUrl('acme', '/employees/new'))
        ->post(tenantUrl('acme', '/employees'), employeePayload([
            'started_on' => '2024-04-01', 'ended_on' => '2024-03-01',
        ]))
        ->assertInvalid(['ended_on']);
});

// -- Editing and ending -----------------------------------------------------

it('edits an employee, showing the stored salary back as a readable figure', function () {
    $this->tenant->run(fn () => Employee::factory()->paidLbp(1_200_000)->create(['name' => 'Karim Aoun']));
    asRole('owner');

    tenancy()->initialize($this->tenant);
    $employee = Employee::sole();

    $this->get(tenantUrl('acme', '/employees/'.$employee->id))
        ->assertOk()
        ->assertSee('Karim Aoun')
        ->assertSee('1,200,000');

    $this->put(tenantUrl('acme', '/employees/'.$employee->id), employeePayload([
        'name' => 'Karim Aoun', 'base_salary' => '1,500,000', 'base_salary_currency' => 'LBP',
    ]))->assertRedirect();

    tenancy()->initialize($this->tenant);
    expect(Employee::sole()->base_salary_minor)->toBe(1_500_000);
});

it('ends employment instead of deleting, so past payroll survives', function () {
    schedule();

    $this->tenant->run(function () {
        Employee::factory()->paidLbp(1_000_000)->create(['name' => 'Karim Aoun']);
        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
    });

    asRole('owner');
    tenancy()->initialize($this->tenant);
    $employee = Employee::sole();

    $this->post(tenantUrl('acme', '/employees/'.$employee->id.'/end'))->assertRedirect();

    tenancy()->initialize($this->tenant);

    // Still there, just ended — and March still has their line.
    expect(Employee::count())->toBe(1)
        ->and(Employee::sole()->ended_on)->not->toBeNull()
        ->and(PayrollLine::where('employee_name', 'Karim Aoun')->count())->toBe(1);
});

it('separates current from former staff on the list', function () {
    $this->tenant->run(function () {
        Employee::factory()->create(['name' => 'Still Here']);
        Employee::factory()->create(['name' => 'Long Gone', 'ended_on' => '2025-01-31']);
    });

    asRole('owner');

    $response = $this->get(tenantUrl('acme', '/employees'))->assertOk();
    $html = $response->getContent();

    $response->assertSee('On the payroll')->assertSee('No longer employed');
    expect(strpos($html, 'Still Here'))->toBeLessThan(strpos($html, 'Long Gone'));
});

// -- Drafting from the app --------------------------------------------------

it('drafts a payroll run from the screen', function () {
    schedule();
    $this->tenant->run(fn () => Employee::factory()->paidLbp(1_000_000)->create());
    asRole('owner');

    $this->post(tenantUrl('acme', '/payroll'), [
        'period_year' => 2026, 'period_month' => 3, 'lbp_per_usd' => 89_500,
    ])->assertRedirect(tenantUrl('acme', '/payroll'));

    tenancy()->initialize($this->tenant);
    expect(PayrollRun::sole()->lines)->toHaveCount(1);
});

it('shows a domain refusal as a form error, not a 500', function () {
    // No rate schedule exists.
    $this->tenant->run(fn () => Employee::factory()->create());
    asRole('owner');

    $this->from(tenantUrl('acme', '/payroll'))
        ->post(tenantUrl('acme', '/payroll'), [
            'period_year' => 2026, 'period_month' => 3, 'lbp_per_usd' => 89_500,
        ])
        ->assertRedirect(tenantUrl('acme', '/payroll'))
        ->assertInvalid(['period_month' => 'No NSSF rate schedule']);
});

it('refuses to finalise from the screen while rates are unverified', function () {
    schedule();
    $this->tenant->run(function () {
        Employee::factory()->create();
        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
    });
    asRole('owner');

    $this->from(tenantUrl('acme', '/payroll'))
        ->post(tenantUrl('acme', '/payroll/finalise'))
        ->assertInvalid(['finalise' => 'not been verified']);

    tenancy()->initialize($this->tenant);
    expect(PayrollRun::sole()->status)->toBe('draft');
});

it('finalises from the screen once the rates are verified', function () {
    verified();
    $this->tenant->run(function () {
        Employee::factory()->create();
        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
    });
    asRole('owner');

    $this->post(tenantUrl('acme', '/payroll/finalise'))->assertRedirect();

    tenancy()->initialize($this->tenant);
    expect(PayrollRun::sole()->status)->toBe('finalised');
});

it('renders the employee form in Arabic with a mirrored layout', function () {
    tenancy()->initialize($this->tenant);
    // Set the preference before signing in — SetAppLocale reads it off the
    // authenticated user, and actingAs holds that instance.
    User::where('role', 'owner')->sole()->update(['locale' => 'ar']);

    $this->actingAs(User::where('role', 'owner')->sole());

    $response = $this->get(tenantUrl('acme', '/employees/new'))->assertOk();

    $response->assertSee('dir="rtl"', false)->assertSee('إضافة أجير', false);
    expect($response->getContent())->not->toMatch('/class="[^"]*\b(pl|pr|ml|mr)-\d/');
});
