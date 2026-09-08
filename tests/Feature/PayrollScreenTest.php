<?php

use App\Models\Employee;
use App\Models\User;
use App\Payroll\RunPayroll;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'rami@acme.test', 'name' => 'Rami', 'locale' => 'en']);
});

it('requires sign-in', function () {
    $this->get(tenantUrl('acme', '/payroll'))->assertRedirect(tenantUrl('acme', '/login'));
});

it('says plainly that it cannot compute when no rate schedule exists', function () {
    tenancy()->initialize($this->tenant);

    $this->actingAs(User::sole())
        ->get(tenantUrl('acme', '/payroll'))
        ->assertOk()
        ->assertSee('NSSF rates are not loaded')
        // The critical property: no fabricated numbers on screen.
        ->assertDontSee('Total cost to the business');
});

it('shows an empty state once rates exist but nothing has been run', function () {
    schedule();
    tenancy()->initialize($this->tenant);

    $this->actingAs(User::sole())
        ->get(tenantUrl('acme', '/payroll'))
        ->assertOk()
        ->assertSee('No payroll yet')
        ->assertDontSee('NSSF rates are not loaded');
});

it('renders a run with its figures', function () {
    schedule();

    $this->tenant->run(function () {
        Employee::factory()->paidLbp(1_000_000)->create(['name' => 'Rami Haddad']);
        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
    });

    tenancy()->initialize($this->tenant);

    $this->actingAs(User::sole())
        ->get(tenantUrl('acme', '/payroll'))
        ->assertOk()
        ->assertSee('2026-03')
        ->assertSee('Rami Haddad')
        ->assertSee('1,000,000')   // gross
        ->assertSee('970,000')     // net after 3%
        ->assertSee('1,225,000');  // total employer cost
});

it('warns on screen when a run used unverified rates', function () {
    schedule(); // unverified

    $this->tenant->run(function () {
        Employee::factory()->create();
        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
    });

    tenancy()->initialize($this->tenant);

    $this->actingAs(User::sole())
        ->get(tenantUrl('acme', '/payroll'))
        ->assertOk()
        ->assertSee('These rates have not been verified');
});

it('drops the warning once the rates are verified', function () {
    verified();

    $this->tenant->run(function () {
        Employee::factory()->create();
        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
    });

    tenancy()->initialize($this->tenant);

    $this->actingAs(User::sole())
        ->get(tenantUrl('acme', '/payroll'))
        ->assertOk()
        ->assertDontSee('These rates have not been verified');
});

it('shows the snapshotted rules, not the current schedule', function () {
    $s = schedule();

    $this->tenant->run(function () {
        Employee::factory()->create();
        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
    });

    $s->update(['sickness_ceiling_lbp' => 99_000_000]);

    tenancy()->initialize($this->tenant);

    $this->actingAs(User::sole())
        ->get(tenantUrl('acme', '/payroll'))
        ->assertOk()
        ->assertSee('2,500,000')      // what the run used
        ->assertDontSee('99,000,000'); // not today's corrected figure
});

it('renders in Arabic with a mirrored layout', function () {
    schedule();

    $this->tenant->run(function () {
        Employee::factory()->create();
        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
    });

    tenancy()->initialize($this->tenant);
    $user = User::sole();
    $user->update(['locale' => 'ar']);

    $response = $this->actingAs($user)->get(tenantUrl('acme', '/payroll'))->assertOk();

    $response->assertSee('dir="rtl"', false)
        ->assertSee('الرواتب', false)
        ->assertSee('الكلفة الإجمالية على العمل', false);

    // Logical properties only, so the table mirrors rather than pinning left.
    expect($response->getContent())->not->toMatch('/class="[^"]*\b(pl|pr|ml|mr)-\d/');
});
