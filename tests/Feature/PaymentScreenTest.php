<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\PaymentMethod;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'owner@acme.test', 'role' => 'owner', 'locale' => 'en']);
});

/** An issued 1,110,000 LBP invoice, returning its id. `seedIssuedInvoice()` is in CreditNoteScreenTest. */
function paidTestInvoice(): int
{
    vatSchedule();

    return seedIssuedInvoice();
}

// -- Recording --------------------------------------------------------------

it('states the balance before the form is submitted', function () {
    $id = paidTestInvoice();
    signInAs();

    $this->get(tenantUrl('acme', '/invoices/'.$id.'/payments/new'))
        ->assertOk()
        // The ceiling, before anything is typed — not only enforced on submit.
        ->assertSee('Outstanding on this invoice right now: 1,110,000 LBP')
        ->assertSee('Whish Money')
        ->assertSee('OMT');
});

it('records a part payment from the screen and shows it in the history', function () {
    $id = paidTestInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '400,000',
        'currency' => 'LBP',
        'method' => 'whish',
        'received_on' => '2026-02-15',
        'reference' => 'WH-77123',
        'notes' => 'Part payment agreed by phone.',
    ])->assertRedirect(tenantUrl('acme', '/invoices/'.$id));

    tenancy()->initialize($this->tenant);
    $payment = Payment::sole();
    expect($payment->amount_minor)->toBe(400_000)
        ->and($payment->method)->toBe(PaymentMethod::Whish)
        ->and($payment->reference)->toBe('WH-77123')
        ->and($payment->received_on->toDateString())->toBe('2026-02-15')
        // Who recorded it, which the old flag never captured.
        ->and($payment->recordedBy->email)->toBe('owner@acme.test')
        ->and(Invoice::sole()->status)->toBe('issued');

    $this->get(tenantUrl('acme', '/invoices/'.$id))
        ->assertOk()
        ->assertSee('Whish Money')
        ->assertSee('400,000')
        ->assertSee('WH-77123')
        ->assertSee('Balance due')
        ->assertSee('710,000');
});

it('refuses an overpayment from the screen, keeping what was typed', function () {
    $id = paidTestInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '2,000,000',
        'currency' => 'LBP',
        'method' => 'cash',
        'received_on' => now()->toDateString(),
    ])
        ->assertSessionHasErrors('amount')
        ->assertSessionHasInput('amount', '2,000,000');

    tenancy()->initialize($this->tenant);
    expect(Payment::count())->toBe(0);

    expect(session('errors')->first('amount'))->toContain('1,110,000 LBP');
});

it('refuses a mismatched currency from the screen', function () {
    $id = paidTestInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '100.00',
        'currency' => 'USD',
        'method' => 'cash',
        'received_on' => now()->toDateString(),
    ])->assertSessionHasErrors('amount');

    tenancy()->initialize($this->tenant);
    expect(Payment::count())->toBe(0);
    expect(session('errors')->first('amount'))->toContain('is a LBP invoice and this payment is in USD');
});

it('redirects away from the form once nothing is outstanding', function () {
    $id = paidTestInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '1,110,000', 'currency' => 'LBP', 'method' => 'cash',
        'received_on' => now()->toDateString(),
    ])->assertRedirect();

    // The button that got you here is gone by now: a stale link, not an error.
    $this->get(tenantUrl('acme', '/invoices/'.$id.'/payments/new'))
        ->assertRedirect(tenantUrl('acme', '/invoices/'.$id));
});

// -- The receipt ------------------------------------------------------------

it('renders a numbered receipt for a payment', function () {
    $id = paidTestInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '600,000', 'currency' => 'LBP', 'method' => 'cheque',
        'received_on' => '2026-02-15', 'reference' => 'Cheque 004417',
    ]);

    tenancy()->initialize($this->tenant);
    $payment = Payment::sole();

    $this->get(tenantUrl('acme', '/receipts/'.$payment->id))
        ->assertOk()
        ->assertSee('RCT-2026-0001')
        ->assertSee('600,000')
        ->assertSee('Cheque')
        ->assertSee('Cheque 004417')
        ->assertSee('2026-02-15')
        ->assertSee('Beirut Foods')
        ->assertSee('INV-2026-0001')
        // The balance still owed, on the receipt itself.
        ->assertSee('510,000')
        ->assertSee('It is not a tax invoice.');
});

// -- Outstanding balances ---------------------------------------------------

it('lists who owes money and drops an invoice once it is settled', function () {
    $id = paidTestInvoice();
    signInAs();

    $this->get(tenantUrl('acme', '/outstanding'))
        ->assertOk()
        ->assertSee('Beirut Foods')
        ->assertSee('INV-2026-0001')
        ->assertSee('Total outstanding')
        ->assertSee('1,110,000');

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '1,110,000', 'currency' => 'LBP', 'method' => 'cash',
        'received_on' => now()->toDateString(),
    ]);

    $this->get(tenantUrl('acme', '/outstanding'))
        ->assertOk()
        ->assertSee('Nothing outstanding');
});

it('shows an overdue partly paid invoice as overdue on the outstanding list', function () {
    $id = paidTestInvoice();
    signInAs();

    tenancy()->initialize($this->tenant);
    Invoice::sole()->forceFill(['due_on' => Carbon::parse('2026-01-31')])->save();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '400,000', 'currency' => 'LBP', 'method' => 'omt',
        'received_on' => '2026-02-15',
    ]);

    $this->get(tenantUrl('acme', '/outstanding'))
        ->assertOk()
        ->assertSee('Overdue · partly paid', escape: false)
        ->assertSee('1 overdue')
        ->assertSee('710,000');
});

// -- The gate ---------------------------------------------------------------

it('lets staff see balances, history and receipts but not record a payment', function () {
    $id = paidTestInvoice();

    signInAs();
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '400,000', 'currency' => 'LBP', 'method' => 'cash',
        'received_on' => now()->toDateString(),
    ]);

    tenancy()->initialize($this->tenant);
    $paymentId = Payment::sole()->id;

    signInAs('staff');

    // Reading is open, the same as the rest of invoicing.
    $this->get(tenantUrl('acme', '/outstanding'))->assertOk()->assertSee('710,000');
    $this->get(tenantUrl('acme', '/invoices/'.$id))->assertOk()->assertSee('Balance due');
    $this->get(tenantUrl('acme', '/receipts/'.$paymentId))->assertOk();

    // Recording is not.
    $this->get(tenantUrl('acme', '/invoices/'.$id.'/payments/new'))->assertForbidden();
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '10,000', 'currency' => 'LBP', 'method' => 'cash',
        'received_on' => now()->toDateString(),
    ])->assertForbidden();

    tenancy()->initialize($this->tenant);
    expect(Payment::count())->toBe(1);
});

it('lets an accountant record a payment', function () {
    $id = paidTestInvoice();
    signInAs('accountant');

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/payments'), [
        'amount' => '100,000', 'currency' => 'LBP', 'method' => 'bank_transfer',
        'received_on' => now()->toDateString(),
    ])->assertRedirect();

    tenancy()->initialize($this->tenant);
    expect(Payment::count())->toBe(1)
        ->and(Payment::sole()->recordedBy->role)->toBe('accountant');
});

it('requires sign-in for every payment screen', function () {
    $id = paidTestInvoice();

    $this->get(tenantUrl('acme', '/outstanding'))->assertRedirect(tenantUrl('acme', '/login'));
    $this->get(tenantUrl('acme', '/invoices/'.$id.'/payments/new'))->assertRedirect(tenantUrl('acme', '/login'));
});

// -- Arabic -----------------------------------------------------------------

it('renders the payment screens in Arabic with logical properties only', function () {
    $id = paidTestInvoice();
    signInAs()->update(['locale' => 'ar']);

    $html = $this->get(tenantUrl('acme', '/invoices/'.$id.'/payments/new'))
        ->assertOk()
        ->assertSee('dir="rtl"', escape: false)
        ->assertSee('تسجيل الدفعة')
        ->getContent();

    // The house rule: no physical direction utilities anywhere on the page.
    expect($html)->not->toMatch('/class="[^"]*\b(pl|pr|ml|mr)-\d/');
});
