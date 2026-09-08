<?php

use App\Models\User;
use App\Models\VatReturn;
use App\Models\VatTransaction;
use App\Vat\PrepareVatReturn;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'owner@acme.test', 'role' => 'owner', 'locale' => 'en']);
});

function signIn(string $role = 'owner'): User
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

it('requires sign-in', function () {
    $this->get(tenantUrl('acme', '/vat'))->assertRedirect(tenantUrl('acme', '/login'));
});

it('says plainly that it cannot compute when no rate schedule exists', function () {
    signIn();

    $this->get(tenantUrl('acme', '/vat'))
        ->assertOk()
        ->assertSee('VAT rates are not loaded')
        // The critical property: no fabricated figures on screen.
        ->assertDontSee('Output VAT');
});

it('shows an empty state once rates exist but nothing is drafted', function () {
    vatSchedule();
    signIn();

    $this->get(tenantUrl('acme', '/vat'))
        ->assertOk()
        ->assertSee('No return yet')
        ->assertDontSee('VAT rates are not loaded');
});

it('renders a return with its figures and transactions', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(10_000_000)->on('2026-02-10')
            ->create(['description' => 'Consulting fee']);
        VatTransaction::factory()->purchase()->net(4_000_000)->on('2026-02-20')
            ->create(['description' => 'Office supplies']);
        app(PrepareVatReturn::class)->forQuarter(2026, 1);
    });

    signIn();

    $this->get(tenantUrl('acme', '/vat'))
        ->assertOk()
        ->assertSee('2026-Q1')
        ->assertSee('Consulting fee')
        ->assertSee('Office supplies')
        ->assertSee('1,100,000')  // output VAT
        ->assertSee('440,000')    // input VAT
        ->assertSee('660,000');   // payable
});

it('shows a credit position rather than a negative payable', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        VatTransaction::factory()->purchase()->net(5_000_000)->on('2026-01-06')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1);
    });

    signIn();

    $this->get(tenantUrl('acme', '/vat'))
        ->assertOk()
        ->assertSee('Credit carried forward')
        ->assertSee('440,000')
        ->assertDontSee('-440,000');
});

it('warns on screen when a return used unverified rates', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1);
    });

    signIn();

    $this->get(tenantUrl('acme', '/vat'))->assertOk()->assertSee('These rates have not been verified');
});

it('drafts a quarter from the screen', function () {
    vatSchedule();
    $this->tenant->run(fn () => VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create());
    signIn();

    $this->post(tenantUrl('acme', '/vat'), ['period_year' => 2026, 'period_quarter' => 1])
        ->assertRedirect(tenantUrl('acme', '/vat'));

    tenancy()->initialize($this->tenant);
    expect(VatReturn::sole()->output_vat_lbp)->toBe(110_000);
});

it('shows a domain refusal as a form error, not a 500', function () {
    // No schedule at all.
    signIn();

    $this->from(tenantUrl('acme', '/vat'))
        ->post(tenantUrl('acme', '/vat'), ['period_year' => 2026, 'period_quarter' => 1])
        ->assertRedirect(tenantUrl('acme', '/vat'))
        ->assertInvalid(['period_quarter' => 'No VAT rate schedule']);
});

it('refuses to file while the rates are unverified', function () {
    vatSchedule();
    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1);
    });
    signIn();

    $this->from(tenantUrl('acme', '/vat'))
        ->post(tenantUrl('acme', '/vat/file'))
        ->assertInvalid(['file' => 'not been verified']);

    tenancy()->initialize($this->tenant);
    expect(VatReturn::sole()->status)->toBe('draft');
});

it('files once the rates are verified', function () {
    vatVerified();
    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1);
    });
    signIn();

    $this->post(tenantUrl('acme', '/vat/file'))->assertRedirect();

    tenancy()->initialize($this->tenant);
    expect(VatReturn::sole()->status)->toBe('finalised');
});

it('does not let staff draft or file', function () {
    vatVerified();
    $this->tenant->run(fn () => VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create());
    signIn('staff');

    $this->post(tenantUrl('acme', '/vat'), ['period_year' => 2026, 'period_quarter' => 1])->assertForbidden();
    $this->post(tenantUrl('acme', '/vat/file'))->assertForbidden();
});

it('still lets staff read the return', function () {
    vatSchedule();
    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1);
    });
    signIn('staff');

    $this->get(tenantUrl('acme', '/vat'))
        ->assertOk()
        ->assertSee('2026-Q1')
        ->assertDontSee('Draft a quarter');
});

it('renders in Arabic with a mirrored layout', function () {
    vatSchedule();
    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1);
    });

    tenancy()->initialize($this->tenant);
    User::where('role', 'owner')->sole()->update(['locale' => 'ar']);
    $this->actingAs(User::where('role', 'owner')->sole());

    $response = $this->get(tenantUrl('acme', '/vat'))->assertOk();

    $response->assertSee('dir="rtl"', false)
        ->assertSee('الضريبة المحصّلة', false)
        ->assertSee('المبيعات الخاضعة', false);

    expect($response->getContent())->not->toMatch('/class="[^"]*\b(pl|pr|ml|mr)-\d/');
});
