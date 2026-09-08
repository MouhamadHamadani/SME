<?php

use App\Invoicing\IssueInvoice;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatTransaction;
use App\Vat\VatTreatment;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'owner@acme.test', 'role' => 'owner', 'locale' => 'en']);
});

/** Lines for the partial-credit form. */
function creditLines(array $overrides = []): array
{
    return ['lines' => [array_merge([
        'description' => 'Consulting',
        'quantity' => '1',
        'unit_price' => '400,000',
        'treatment' => 'standard',
    ], $overrides)]];
}

it('shows the form pre-filled from the invoice', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();

    $this->get(tenantUrl('acme', '/invoices/'.$id.'/credit'))
        ->assertOk()
        // Pre-filled so a full credit is one edit away, not a re-type.
        ->assertSee('value="Consulting"', false)
        ->assertSee('value="1,000,000"', false)
        // And the ceiling is stated up front, not only enforced on submit.
        ->assertSee('Left to credit')
        ->assertSee('1,110,000');
});

it('issues a partial credit note', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'), creditLines())
        ->assertRedirect();

    tenancy()->initialize($this->tenant);
    $note = CreditNote::sole();

    // 400,000 net + 11% = 444,000
    expect($note->subtotal_minor)->toBe(400_000)
        ->and($note->vat_minor)->toBe(44_000)
        ->and($note->total_minor)->toBe(444_000)
        ->and($note->credited_invoice_id)->toBe($id);

    // The invoice keeps the rest available.
    expect(Invoice::sole()->creditableMinor())->toBe(666_000);
});

it('writes a negative VAT transaction for the partial amount only', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'), creditLines());

    tenancy()->initialize($this->tenant);
    $credit = VatTransaction::where('net_lbp', '<', 0)->sole();

    expect($credit->net_lbp)->toBe(-400_000)
        ->and($credit->vat_lbp)->toBe(-44_000);
});

it('allows a second credit up to the remaining balance', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'), creditLines());
    // 666,000 remains; 600,000 + 11% = 666,000 exactly.
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'), creditLines(['unit_price' => '600,000']))
        ->assertRedirect();

    tenancy()->initialize($this->tenant);

    expect(CreditNote::count())->toBe(2)
        ->and(Invoice::sole()->creditableMinor())->toBe(0)
        ->and(Invoice::sole()->isFullyCredited())->toBeTrue();
});

it('refuses a partial credit that would exceed the remaining balance', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'), creditLines());

    // Only 666,000 is left; 700,000 + 11% = 777,000.
    $this->from(tenantUrl('acme', '/invoices/'.$id.'/credit'))
        ->post(tenantUrl('acme', '/invoices/'.$id.'/credit'), creditLines(['unit_price' => '700,000']))
        ->assertRedirect(tenantUrl('acme', '/invoices/'.$id.'/credit'))
        ->assertInvalid(['issue' => 'credit more than']);

    tenancy()->initialize($this->tenant);

    // Nothing was written — the whole issue is one transaction.
    expect(CreditNote::count())->toBe(1)
        ->and(VatTransaction::where('net_lbp', '<', 0)->count())->toBe(1);
});

it('validates each line', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();
    $url = tenantUrl('acme', '/invoices/'.$id.'/credit');

    $this->from($url)->post($url, creditLines(['quantity' => 'two']))
        ->assertInvalid(['lines.0.quantity']);

    $this->from($url)->post($url, creditLines(['unit_price' => '10.5']))
        ->assertInvalid(['lines.0.unit_price']); // LBP has no subunit

    $this->from($url)->post($url, creditLines(['unit_price' => '0']))
        ->assertInvalid(['lines.0.unit_price' => 'more than zero']);

    $this->from($url)->post($url, ['lines' => []])
        ->assertInvalid(['lines']);
});

it('credits multiple lines with mixed treatments', function () {
    vatSchedule();

    $id = $this->tenant->run(function () {
        $invoice = draftInvoice([
            ['qty' => 1000, 'price' => 10_000_000, 'treatment' => VatTreatment::Standard],
            ['qty' => 1000, 'price' => 9_000_000, 'treatment' => VatTreatment::Exempt],
        ]);

        return app(IssueInvoice::class)
            ->issue($invoice, Carbon::parse('2026-02-10'))->id;
    });

    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'), ['lines' => [
        ['description' => 'Consulting', 'quantity' => '0.5', 'unit_price' => '10,000,000', 'treatment' => 'standard'],
        ['description' => 'Procedure', 'quantity' => '1', 'unit_price' => '2,000,000', 'treatment' => 'exempt'],
    ]])->assertRedirect();

    tenancy()->initialize($this->tenant);
    $credits = VatTransaction::where('net_lbp', '<', 0)->get()->keyBy(fn ($t) => $t->treatment->value);

    // Each treatment reversed separately, at the partial amounts.
    expect($credits)->toHaveCount(2)
        ->and($credits['standard']->net_lbp)->toBe(-5_000_000)
        ->and($credits['standard']->vat_lbp)->toBe(-550_000)
        ->and($credits['exempt']->net_lbp)->toBe(-2_000_000)
        ->and($credits['exempt']->vat_lbp)->toBe(0);
});

it('sends you back to the invoice when there is nothing left to credit', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs();

    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit/full'));

    // A stale link, not an error worth a page.
    $this->get(tenantUrl('acme', '/invoices/'.$id.'/credit'))
        ->assertRedirect(tenantUrl('acme', '/invoices/'.$id));
});

it('does not let staff open or submit the form', function () {
    vatSchedule();
    $id = seedIssuedInvoice();
    signInAs('staff');

    $this->get(tenantUrl('acme', '/invoices/'.$id.'/credit'))->assertForbidden();
    $this->post(tenantUrl('acme', '/invoices/'.$id.'/credit'), creditLines())->assertForbidden();
});

it('renders the form in Arabic with a mirrored layout', function () {
    vatSchedule();
    $id = seedIssuedInvoice();

    tenancy()->initialize($this->tenant);
    User::where('role', 'owner')->sole()->update(['locale' => 'ar']);
    $this->actingAs(User::where('role', 'owner')->sole());

    $response = $this->get(tenantUrl('acme', '/invoices/'.$id.'/credit'))->assertOk();

    $response->assertSee('dir="rtl"', false)
        ->assertSee('إصدار إشعار دائن', false)
        ->assertSee('المتبقّي للمقاصّة', false);

    expect($response->getContent())->not->toMatch('/class="[^"]*\b(pl|pr|ml|mr)-\d/');
});
