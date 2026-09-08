<?php

use App\Invoicing\IssueInvoice;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Currency;
use App\Vat\VatTreatment;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'owner@acme.test', 'role' => 'owner', 'locale' => 'en']);
});

/** An issued invoice, returning its id. */
function seedIssuedInvoice(): int
{
    return test()->tenant->run(function () {
        $customer = Customer::factory()->create(['name' => 'Beirut Foods']);
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'status' => 'draft', 'currency' => Currency::LBP,
        ]);
        $invoice->lines()->create([
            'position' => 0, 'description' => 'Consulting', 'quantity_milli' => 1000,
            'unit_price_minor' => 1_000_000, 'treatment' => VatTreatment::Standard,
            'net_minor' => 0, 'vat_minor' => 0,
        ]);

        return app(IssueInvoice::class)->issue($invoice->fresh('lines'), Carbon::parse('2026-02-10'))->id;
    });
}

function signInAs(string $role = 'owner'): User
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

it('offers to credit an issued invoice', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();

    $this->get(tenantUrl('acme', '/invoices/'.$id))
        ->assertOk()
        ->assertSee('Credit in full')
        // The explanation matters: people reach for Void first.
        ->assertSee('after the VAT period has been filed', false);
});

it('issues a credit note from the screen', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'))->assertRedirect();

    tenancy()->initialize($this->tenant);
    $note = CreditNote::sole();

    expect($note->reference)->toBe('CN-'.now()->year.'-0001')
        ->and($note->credited_invoice_id)->toBe($id)
        ->and($note->total_minor)->toBe(1_110_000);
});

it('shows the credit note and links back to the invoice', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'));

    tenancy()->initialize($this->tenant);
    $note = CreditNote::sole();

    $this->get(tenantUrl('acme', '/credit-notes/'.$note->id))
        ->assertOk()
        ->assertSee($note->reference)
        ->assertSee('Against')
        ->assertSee('INV-2026-0001')
        // A credit note is neither paid nor voided.
        ->assertDontSee('Mark as paid')
        ->assertDontSee('Credit in full');
});

it('lists credit notes on the invoice with what is left to credit', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'));

    $this->get(tenantUrl('acme', '/invoices/'.$id))
        ->assertOk()
        ->assertSee('Credit notes')
        ->assertSee('CN-'.now()->year.'-0001')
        ->assertSee('Left to credit');
});

it('reports over-crediting as an error rather than a 500', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'));

    $this->from(tenantUrl('acme', '/invoices/'.$id))
        ->post(tenantUrl('acme', '/invoices/'.$id.'/credit'))
        ->assertRedirect(tenantUrl('acme', '/invoices/'.$id))
        ->assertInvalid(['issue' => 'already been credited in full']);
});

it('keeps credit notes off the invoice list', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'));

    $html = $this->get(tenantUrl('acme', '/invoices'))->assertOk()->getContent();

    expect(substr_count($html, 'INV-2026-0001'))->toBeGreaterThan(0)
        ->and($html)->not->toContain('CN-'.now()->year.'-0001');
});

it('does not let staff credit an invoice', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs('staff');

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'))->assertForbidden();
});

it('renders a credit note in Arabic with a mirrored layout', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'));

    tenancy()->initialize($this->tenant);
    $note = CreditNote::sole();
    User::where('role', 'owner')->sole()->update(['locale' => 'ar']);
    $this->actingAs(User::where('role', 'owner')->sole());

    $response = $this->get(tenantUrl('acme', '/credit-notes/'.$note->id))->assertOk();

    $response->assertSee('dir="rtl"', false)->assertSee('عن الفاتورة', false);
    expect($response->getContent())->not->toMatch('/class="[^"]*\b(pl|pr|ml|mr)-\d/');
});
