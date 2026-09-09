<?php

use App\Invoicing\Exceptions\InvoicingException;
use App\Invoicing\IssueCreditNote;
use App\Invoicing\IssueInvoice;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\VatReturn;
use App\Models\VatTransaction;
use App\Support\Currency;
use App\Vat\PrepareVatReturn;
use App\Vat\VatTreatment;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
});

// -- The reason it exists ---------------------------------------------------

it('credits into its own period, leaving a filed return untouched', function () {
    /*
     | The whole point. Voiding is refused once a period is filed; a credit
     | note corrects it by reducing VAT in the period the NOTE is issued.
     */
    vatVerified();

    $this->tenant->run(function () {
        $invoice = issuedInvoice(on: '2026-02-10');           // Q1
        app(PrepareVatReturn::class)->forQuarter(2026, 1)->finalise();

        // Voiding is not available any more.
        expect(fn () => $invoice->fresh()->void('Cancelled'))
            ->toThrow(InvoicingException::class, 'already included in a filed VAT return');

        // The credit note is.
        app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-15')); // Q2

        // Q1 is untouched.
        $q1 = VatReturn::where('period_quarter', 1)->sole();
        expect($q1->output_vat_lbp)->toBe(110_000)
            ->and($q1->status)->toBe('finalised');

        // Q2 carries the reversal.
        $q2 = app(PrepareVatReturn::class)->forQuarter(2026, 2);
        expect($q2->output_vat_lbp)->toBe(-110_000)
            ->and($q2->taxable_sales_lbp)->toBe(-1_000_000);
    });
});

it('writes negative VAT transactions', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-15'));

        $credit = VatTransaction::where('net_lbp', '<', 0)->sole();

        expect($credit->net_lbp)->toBe(-1_000_000)
            ->and($credit->vat_lbp)->toBe(-110_000)
            ->and($credit->direction)->toBe('sale')
            ->and($credit->transacted_on->toDateString())->toBe('2026-05-15');
    });
});

it('nets out to zero when invoice and credit fall in the same quarter', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice(on: '2026-02-10');
        app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-03-01'));

        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        expect($return->output_vat_lbp)->toBe(0)
            ->and($return->taxable_sales_lbp)->toBe(0)
            ->and($return->payable_lbp)->toBe(0);
    });
});

// -- Numbering and identity -------------------------------------------------

it('numbers credit notes in their own series', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $a = issuedInvoice();
        $b = issuedInvoice();

        expect($a->reference)->toBe('INV-2026-0001')
            ->and($b->reference)->toBe('INV-2026-0002');

        $first = app(IssueCreditNote::class)->full($a->fresh('lines'), Carbon::parse('2026-05-01'));
        $second = app(IssueCreditNote::class)->full($b->fresh('lines'), Carbon::parse('2026-05-02'));

        // A separate series starting at 1 — not a continuation of INV.
        expect($first->reference)->toBe('CN-2026-0001')
            ->and($second->reference)->toBe('CN-2026-0002')
            ->and($first->number)->toBe(1);
    });
});

it('keeps credit notes out of invoice queries', function () {
    // The global scope is what makes single-table storage safe. Without it a
    // credit note would appear on the invoice list and in every total.
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-01'));

        expect(Invoice::count())->toBe(1)
            ->and(Invoice::issued()->count())->toBe(1)
            ->and(CreditNote::count())->toBe(1)
            ->and(Invoice::first()->reference)->toStartWith('INV-')
            ->and(CreditNote::first()->reference)->toStartWith('CN-');
    });
});

it('links back to the invoice it corrects', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        $note = app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-01'));

        expect($note->creditedInvoice->id)->toBe($invoice->id)
            ->and($invoice->fresh()->creditNotes()->count())->toBe(1);
    });
});

// -- Over-crediting ---------------------------------------------------------

it('will not credit more than the invoice is worth', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice([['qty' => 1000, 'price' => 1_000_000]]);

        // A partial credit of 600,000 leaves 510,000 of the 1,110,000 total.
        app(IssueCreditNote::class)->issue($invoice->fresh('lines'), [[
            'position' => 0, 'description' => 'Partial', 'quantity_milli' => 1000,
            'unit_price_minor' => 600_000, 'treatment' => VatTreatment::Standard,
            'net_minor' => 0, 'vat_minor' => 0,
        ]], Carbon::parse('2026-05-01'));

        expect($invoice->fresh()->creditedMinor())->toBe(666_000)   // 600k + 11%
            ->and($invoice->fresh()->creditableMinor())->toBe(444_000);

        // A second credit for the full amount would exceed what is left.
        expect(fn () => app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-02')))
            ->toThrow(InvoicingException::class, 'credit more than');
    });
});

it('refuses once an invoice is fully credited', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-01'));

        expect($invoice->fresh()->isFullyCredited())->toBeTrue();

        expect(fn () => app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-02')))
            ->toThrow(InvoicingException::class, 'already been credited in full');
    });
});

it('does not count draft credit notes toward the credited total', function () {
    // A draft can still change; letting it reserve headroom would allow two
    // drafts to each look affordable while together they over-credit.
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();

        CreditNote::create([
            'customer_id' => $invoice->customer_id,
            'credited_invoice_id' => $invoice->id,
            'status' => 'draft',
            'currency' => Currency::LBP,
            'total_minor' => 1_110_000,
        ]);

        expect($invoice->fresh()->creditedMinor())->toBe(0)
            ->and($invoice->fresh()->creditableMinor())->toBe(1_110_000);
    });
});

// -- What cannot be credited ------------------------------------------------

it('refuses to credit a draft or a void invoice', function () {
    vatSchedule();

    $this->tenant->run(function () {
        expect(fn () => app(IssueCreditNote::class)->full(draftInvoice()))
            ->toThrow(InvoicingException::class, 'cannot be credited');

        $invoice = issuedInvoice();
        $invoice->void('Cancelled');

        expect(fn () => app(IssueCreditNote::class)->full($invoice->fresh('lines')))
            ->toThrow(InvoicingException::class, 'cannot be credited');
    });
});

it('refuses to issue without a VAT rate schedule for the credit note date', function () {
    vatSchedule(['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);

    $this->tenant->run(function () {
        $invoice = issuedInvoice(on: '2026-02-10');

        // No schedule covers May.
        expect(fn () => app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-01')))
            ->toThrow(InvoicingException::class, 'No VAT rate schedule');
    });
});

it('cannot be paid or voided', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        $note = app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-01'));

        expect(fn () => $note->markPaid())
            ->toThrow(InvoicingException::class, 'reduces what is owed');
        expect(fn () => $note->void('nope'))
            ->toThrow(InvoicingException::class, 'cannot be voided');
    });
});

// -- Mixed treatments -------------------------------------------------------

it('reverses each treatment separately', function () {
    // Same reasoning as the invoice split: correcting the total while
    // corrupting the taxable/exempt classification is still a wrong return.
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice([
            ['qty' => 1000, 'price' => 10_000_000, 'treatment' => VatTreatment::Standard],
            ['qty' => 1000, 'price' => 9_000_000, 'treatment' => VatTreatment::Exempt],
        ], on: '2026-02-10');

        app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-15'));

        $credits = VatTransaction::where('net_lbp', '<', 0)->get()->keyBy(fn ($t) => $t->treatment->value);

        expect($credits)->toHaveCount(2)
            ->and($credits['standard']->net_lbp)->toBe(-10_000_000)
            ->and($credits['standard']->vat_lbp)->toBe(-1_100_000)
            ->and($credits['exempt']->net_lbp)->toBe(-9_000_000)
            ->and($credits['exempt']->vat_lbp)->toBe(0);

        $q2 = app(PrepareVatReturn::class)->forQuarter(2026, 2);
        expect($q2->taxable_sales_lbp)->toBe(-10_000_000)
            ->and($q2->exempt_sales_lbp)->toBe(-9_000_000);
    });
});

it('credits a USD invoice at the invoice rate', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice(
            [['qty' => 1000, 'price' => 50_000]],
            '2026-02-10',
            ['currency' => Currency::USD, 'lbp_per_usd' => 89_500],
        );

        $note = app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-15'));

        expect($note->currency)->toBe(Currency::USD)
            ->and($note->subtotal_minor)->toBe(50_000)
            ->and(VatTransaction::where('net_lbp', '<', 0)->sole()->net_lbp)->toBe(-44_750_000);
    });
});

it('keeps one business\'s credit notes out of another\'s', function () {
    vatSchedule();
    $globex = makeTenant('globex');

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-05-01'));
    });

    $globex->run(fn () => expect(CreditNote::count())->toBe(0));
});
