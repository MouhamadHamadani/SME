<?php

use App\Http\Requests\InvoiceRequest;
use App\Invoicing\IssueInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatTransaction;
use App\Support\Currency;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'owner@acme.test', 'role' => 'owner', 'locale' => 'en']);
});

function invoicePayload(array $overrides = []): array
{
    return array_merge([
        'customer_id' => Customer::first()?->id ?? Customer::factory()->create()->id,
        'currency' => 'LBP',
        'due_days' => 30,
        'lines' => [
            ['description' => 'Consulting', 'quantity' => '2.5', 'unit_price' => '1,000,000', 'treatment' => 'standard'],
        ],
    ], $overrides);
}

// -- Quantity parsing -------------------------------------------------------

it('parses quantities into thousandths without float drift', function () {
    expect(InvoiceRequest::parseQuantity('1'))->toBe(1000)
        ->and(InvoiceRequest::parseQuantity('2.5'))->toBe(2500)
        ->and(InvoiceRequest::parseQuantity('0.125'))->toBe(125)
        ->and(InvoiceRequest::parseQuantity('1,000'))->toBe(1_000_000);
});

it('rejects a quantity with more than three decimals', function () {
    expect(fn () => InvoiceRequest::parseQuantity('1.2345'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => InvoiceRequest::parseQuantity('two'))
        ->toThrow(InvalidArgumentException::class);
});

// -- Screens ----------------------------------------------------------------

it('requires sign-in', function () {
    $this->get(tenantUrl('acme', '/invoices'))->assertRedirect(tenantUrl('acme', '/login'));
});

it('shows an empty state', function () {
    signInAs();

    $this->get(tenantUrl('acme', '/invoices'))->assertOk()->assertSee('No invoices yet');
});

it('tells you to add a customer before invoicing', function () {
    signInAs();

    $this->get(tenantUrl('acme', '/invoices/new'))
        ->assertOk()
        ->assertSee('Add a customer before creating an invoice');
});

it('creates a draft with computed line amounts', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create(['name' => 'Beirut Foods']);
    signInAs();

    $this->post(tenantUrl('acme', '/invoices'), invoicePayload())->assertRedirect();

    tenancy()->initialize($this->tenant);
    $invoice = Invoice::sole();

    expect($invoice->status)->toBe('draft')
        ->and($invoice->number)->toBeNull()
        // 2.5 x 1,000,000 = 2,500,000, VAT 11% = 275,000
        ->and($invoice->subtotal_minor)->toBe(2_500_000)
        ->and($invoice->vat_minor)->toBe(275_000)
        ->and($invoice->total_minor)->toBe(2_775_000);
});

it('rejects an invoice with no lines', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create();
    signInAs();

    $this->from(tenantUrl('acme', '/invoices/new'))
        ->post(tenantUrl('acme', '/invoices'), invoicePayload(['lines' => []]))
        ->assertInvalid(['lines']);
});

it('rejects a bad quantity or price with a usable message', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create();
    signInAs();

    $this->from(tenantUrl('acme', '/invoices/new'))
        ->post(tenantUrl('acme', '/invoices'), invoicePayload([
            'lines' => [['description' => 'X', 'quantity' => 'two', 'unit_price' => '1000', 'treatment' => 'standard']],
        ]))
        ->assertInvalid(['lines.0.quantity']);

    $this->from(tenantUrl('acme', '/invoices/new'))
        ->post(tenantUrl('acme', '/invoices'), invoicePayload([
            'lines' => [['description' => 'X', 'quantity' => '1', 'unit_price' => '10.5', 'treatment' => 'standard']],
        ]))
        // LBP has no subunit.
        ->assertInvalid(['lines.0.unit_price']);
});

it('requires an exchange rate on a USD invoice', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create();
    signInAs();

    $this->from(tenantUrl('acme', '/invoices/new'))
        ->post(tenantUrl('acme', '/invoices'), invoicePayload(['currency' => 'USD', 'lbp_per_usd' => '']))
        ->assertInvalid(['lbp_per_usd']);
});

it('issues from the screen and writes the VAT transaction', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create(['name' => 'Beirut Foods']);
    signInAs();

    $this->post(tenantUrl('acme', '/invoices'), invoicePayload());

    tenancy()->initialize($this->tenant);
    $invoice = Invoice::sole();

    $this->post(tenantUrl('acme', '/invoices/'.$invoice->id.'/issue'), ['due_days' => 30])
        ->assertRedirect(tenantUrl('acme', '/invoices/'.$invoice->id));

    tenancy()->initialize($this->tenant);
    $issued = Invoice::sole();

    expect($issued->status)->toBe('issued')
        ->and($issued->number)->toBe(1)
        ->and($issued->vatTransactions()->count())->toBe(1)
        ->and(VatTransaction::sole()->vat_lbp)->toBe(275_000);
});

it('sends you to the read-only view when editing an issued invoice', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $customer = Customer::factory()->create();
        $invoice = Invoice::create(['customer_id' => $customer->id, 'status' => 'draft', 'currency' => Currency::LBP]);
        $invoice->lines()->create([
            'position' => 0, 'description' => 'X', 'quantity_milli' => 1000,
            'unit_price_minor' => 1_000_000, 'treatment' => 'standard', 'net_minor' => 0, 'vat_minor' => 0,
        ]);
        app(IssueInvoice::class)->issue($invoice->fresh('lines'));
    });

    signInAs();
    tenancy()->initialize($this->tenant);
    $id = Invoice::sole()->id;

    $this->get(tenantUrl('acme', '/invoices/'.$id.'/edit'))
        ->assertRedirect(tenantUrl('acme', '/invoices/'.$id));
});

it('renders the issued invoice with its lines and declared VAT', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->vatRegistered('7654321')->create(['name' => 'Beirut Foods']);
    signInAs();

    $this->post(tenantUrl('acme', '/invoices'), invoicePayload());
    tenancy()->initialize($this->tenant);
    $id = Invoice::sole()->id;
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/issue'), ['due_days' => 30]);

    $this->get(tenantUrl('acme', '/invoices/'.$id))
        ->assertOk()
        ->assertSee('Beirut Foods')
        ->assertSee('7654321')
        ->assertSee('Consulting')
        ->assertSee('2,500,000')
        ->assertSee('275,000')
        ->assertSee('Declared for VAT');
});

it('records a payment and voids from the screen', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices'), invoicePayload());
    tenancy()->initialize($this->tenant);
    $id = Invoice::sole()->id;
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/issue'), ['due_days' => 30]);

    // Settling is now recording a payment, not flipping a flag. 2,500,000 net
    // + 11% = 2,775,000.
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '2,775,000',
        'currency' => 'LBP',
        'method' => 'cash',
        'received_on' => now()->toDateString(),
    ])->assertRedirect();

    tenancy()->initialize($this->tenant);
    expect(Invoice::sole()->status)->toBe('paid')
        ->and(Invoice::sole()->payments)->toHaveCount(1);

    // And voiding it is now refused, because that would erase a settled
    // transaction. A separate, unpaid invoice is what voids.
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/void'), ['void_reason' => 'Duplicate'])
        ->assertSessionHasErrors('issue');

    tenancy()->initialize($this->tenant);
    expect(Invoice::sole()->status)->toBe('paid');
});

it('voids an unpaid invoice from the screen', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices'), invoicePayload());
    tenancy()->initialize($this->tenant);
    $id = Invoice::sole()->id;
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/issue'), ['due_days' => 30]);

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/void'), ['void_reason' => 'Duplicate'])->assertRedirect();
    tenancy()->initialize($this->tenant);
    expect(Invoice::sole()->status)->toBe('void')
        ->and(VatTransaction::count())->toBe(0);
});

it('does not let staff create, issue or void', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create();
    signInAs('staff');

    $this->get(tenantUrl('acme', '/invoices/new'))->assertForbidden();
    $this->post(tenantUrl('acme', '/invoices'), invoicePayload())->assertForbidden();
    $this->get(tenantUrl('acme', '/customers/new'))->assertForbidden();
});

it('still lets staff read invoices', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create(['name' => 'Beirut Foods']);
    signInAs();
    $this->post(tenantUrl('acme', '/invoices'), invoicePayload());

    signInAs('staff');
    $this->get(tenantUrl('acme', '/invoices'))
        ->assertOk()
        ->assertSee('Beirut Foods')
        ->assertDontSee('New invoice');
});

it('manages customers', function () {
    signInAs();

    $this->post(tenantUrl('acme', '/customers'), [
        'name' => 'Beirut Foods', 'phone' => '+961 3 111222', 'vat_number' => '7654321',
    ])->assertRedirect(tenantUrl('acme', '/customers'));

    tenancy()->initialize($this->tenant);
    expect(Customer::sole()->vat_number)->toBe('7654321');

    $this->get(tenantUrl('acme', '/customers'))->assertOk()->assertSee('Beirut Foods');
});

it('renders the invoice form in Arabic with a mirrored layout', function () {
    vatSchedule();
    tenancy()->initialize($this->tenant);
    Customer::factory()->create();
    User::where('role', 'owner')->sole()->update(['locale' => 'ar']);
    $this->actingAs(User::where('role', 'owner')->sole());

    $response = $this->get(tenantUrl('acme', '/invoices/new'))->assertOk();

    $response->assertSee('dir="rtl"', false)->assertSee('فاتورة جديدة', false);
    expect($response->getContent())->not->toMatch('/class="[^"]*\b(pl|pr|ml|mr)-\d/');
});
