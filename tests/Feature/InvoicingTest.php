<?php

use App\Invoicing\Exceptions\InvoicingException;
use App\Invoicing\IssueInvoice;
use App\Models\Customer;
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

/** A draft invoice with lines, inside the current tenant. */
function draftInvoice(?array $lines = null, array $overrides = []): Invoice
{
    $invoice = Invoice::create(array_merge([
        'customer_id' => Customer::factory()->create()->id,
        'status' => 'draft',
        'currency' => Currency::LBP,
    ], $overrides));

    foreach ($lines ?? [['qty' => 1000, 'price' => 1_000_000, 'treatment' => VatTreatment::Standard]] as $i => $l) {
        $invoice->lines()->create([
            'position' => $i,
            'description' => $l['description'] ?? 'Line '.($i + 1),
            'quantity_milli' => $l['qty'],
            'unit_price_minor' => $l['price'],
            'treatment' => $l['treatment'] ?? VatTreatment::Standard,
            'net_minor' => 0,
            'vat_minor' => 0,
        ]);
    }

    return $invoice->fresh('lines');
}

// -- Line arithmetic --------------------------------------------------------

it('multiplies quantity by unit price exactly', function () {
    vatSchedule();

    $this->tenant->run(function () {
        // 2.5 x 1,000,000 = 2,500,000
        $invoice = draftInvoice([['qty' => 2500, 'price' => 1_000_000]]);
        app(IssueInvoice::class)->issue($invoice);

        expect($invoice->fresh()->subtotal_minor)->toBe(2_500_000)
            ->and($invoice->fresh()->vat_minor)->toBe(275_000); // 11%
    });
});

it('rounds a fractional line half up, once', function () {
    vatSchedule();

    $this->tenant->run(function () {
        // 2.5 x 1,333 = 3,332.5 -> 3,333
        $invoice = draftInvoice([['qty' => 2500, 'price' => 1_333]]);
        app(IssueInvoice::class)->issue($invoice);

        expect($invoice->fresh()->lines->sole()->net_minor)->toBe(3_333);
    });
});

it('computes VAT per line and sums, not on the subtotal', function () {
    vatSchedule();

    $this->tenant->run(function () {
        // Three lines of 5 lira: 11% of 5 = 0.55 -> 1 each, so 3 total.
        // 11% of the 15 subtotal would be 1.65 -> 2. The invoice must agree
        // with the lines it prints.
        $invoice = draftInvoice([
            ['qty' => 1000, 'price' => 5],
            ['qty' => 1000, 'price' => 5],
            ['qty' => 1000, 'price' => 5],
        ]);
        app(IssueInvoice::class)->issue($invoice);

        $fresh = $invoice->fresh('lines');

        expect($fresh->lines->sum('vat_minor'))->toBe(3)
            ->and($fresh->vat_minor)->toBe(3)
            ->and($fresh->vat_minor)->not->toBe(2);
    });
});

it('keeps subtotal plus VAT equal to the total', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = draftInvoice([
            ['qty' => 1500, 'price' => 777_777],
            ['qty' => 2250, 'price' => 3_331],
        ]);
        app(IssueInvoice::class)->issue($invoice);

        $f = $invoice->fresh();
        expect($f->total_minor)->toBe($f->subtotal_minor + $f->vat_minor);
    });
});

it('charges no VAT on zero-rated or exempt lines', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = draftInvoice([
            ['qty' => 1000, 'price' => 1_000_000, 'treatment' => VatTreatment::Standard],
            ['qty' => 1000, 'price' => 1_000_000, 'treatment' => VatTreatment::ZeroRated],
            ['qty' => 1000, 'price' => 1_000_000, 'treatment' => VatTreatment::Exempt],
        ]);
        app(IssueInvoice::class)->issue($invoice);

        $f = $invoice->fresh('lines');
        expect($f->subtotal_minor)->toBe(3_000_000)
            ->and($f->vat_minor)->toBe(110_000); // only the standard line
    });
});

// -- Issuing ----------------------------------------------------------------

it('refuses to issue without a VAT rate schedule', function () {
    $this->tenant->run(function () {
        $invoice = draftInvoice();

        expect(fn () => app(IssueInvoice::class)->issue($invoice))
            ->toThrow(InvoicingException::class, 'No VAT rate schedule');
    });
});

it('refuses to issue an empty invoice', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = draftInvoice([]);

        expect(fn () => app(IssueInvoice::class)->issue($invoice))
            ->toThrow(InvoicingException::class, 'at least one line');
    });
});

it('refuses a USD invoice with no exchange rate', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = draftInvoice(null, ['currency' => Currency::USD]);

        expect(fn () => app(IssueInvoice::class)->issue($invoice))
            ->toThrow(InvoicingException::class, 'LBP/USD rate');
    });
});

it('numbers invoices sequentially with no gaps', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $refs = [];
        foreach (range(1, 3) as $i) {
            $refs[] = app(IssueInvoice::class)->issue(draftInvoice())->reference;
        }

        expect(Invoice::issued()->pluck('number')->all())->toBe([1, 2, 3])
            ->and($refs[0])->toStartWith('INV-')
            ->and($refs[0])->toEndWith('-0001');
    });
});

it('does not give a draft a number', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $draft = draftInvoice();

        // A draft that is abandoned must never have consumed a number, or the
        // tax invoice series has a hole in it.
        expect($draft->number)->toBeNull()
            ->and($draft->reference)->toBeNull();

        app(IssueInvoice::class)->issue(draftInvoice());

        expect(Invoice::issued()->sole()->number)->toBe(1);
    });
});

it('does not reuse the number of a voided invoice', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $first = app(IssueInvoice::class)->issue(draftInvoice());
        $first->void('Cancelled by customer');

        $second = app(IssueInvoice::class)->issue(draftInvoice());

        // A hole in the series is a question; a duplicate is a problem.
        expect($second->number)->toBe(2);
    });
});

it('refuses to edit an issued invoice', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = app(IssueInvoice::class)->issue(draftInvoice());

        expect($invoice->isEditable())->toBeFalse();
        expect(fn () => app(IssueInvoice::class)->issue($invoice))
            ->toThrow(InvoicingException::class, 'cannot be changed');
    });
});

it('snapshots the VAT rate it was issued under', function () {
    $s = vatSchedule();

    $this->tenant->run(function () {
        $invoice = app(IssueInvoice::class)->issue(draftInvoice());
        expect($invoice->vat_rate_bps)->toBe(1100);
    });

    $s->update(['standard_rate_bps' => 1500]);

    $this->tenant->run(function () {
        expect(Invoice::issued()->sole()->vat_rate_bps)->toBe(1100);
    });
});

// -- The VAT hand-off -------------------------------------------------------

it('writes a VAT transaction when issued', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $customer = Customer::factory()->vatRegistered('7654321')->create(['name' => 'Beirut Foods']);
        $invoice = draftInvoice(null, ['customer_id' => $customer->id]);

        $issued = app(IssueInvoice::class)->issue($invoice, Carbon::parse('2026-02-10'));

        $t = VatTransaction::sole();

        expect($t->invoice_id)->toBe($issued->id)
            ->and($t->direction)->toBe('sale')
            ->and($t->transacted_on->toDateString())->toBe('2026-02-10')
            ->and($t->counterparty)->toBe('Beirut Foods')
            ->and($t->counterparty_vat_number)->toBe('7654321')
            ->and($t->net_lbp)->toBe(1_000_000)
            ->and($t->vat_lbp)->toBe(110_000)
            ->and($t->description)->toContain($issued->reference);
    });
});

it('flows an issued invoice through to the VAT return', function () {
    // The whole point of the module: one source of truth for the return.
    vatSchedule();

    $this->tenant->run(function () {
        app(IssueInvoice::class)->issue(draftInvoice(), Carbon::parse('2026-02-10'));

        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        expect($return->taxable_sales_lbp)->toBe(1_000_000)
            ->and($return->output_vat_lbp)->toBe(110_000)
            ->and($return->payable_lbp)->toBe(110_000);
    });
});

it('splits a mixed invoice into one VAT transaction per treatment', function () {
    /*
     | Regression. Reporting a mixed invoice under a single "dominant"
     | treatment put the exempt amount into TAXABLE turnover — a clinic
     | billing consultations alongside exempt procedures would have overstated
     | taxable sales by exactly its exempt lines, on a return somebody signs.
     */
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = draftInvoice([
            ['qty' => 1000, 'price' => 10_000_000, 'treatment' => VatTreatment::Standard],
            ['qty' => 1000, 'price' => 9_000_000, 'treatment' => VatTreatment::Exempt],
            ['qty' => 1000, 'price' => 6_000_000, 'treatment' => VatTreatment::ZeroRated],
        ]);

        app(IssueInvoice::class)->issue($invoice, Carbon::parse('2026-02-10'));

        expect(VatTransaction::count())->toBe(3);

        $byTreatment = VatTransaction::get()->keyBy(fn ($t) => $t->treatment->value);
        expect($byTreatment['standard']->net_lbp)->toBe(10_000_000)
            ->and($byTreatment['standard']->vat_lbp)->toBe(1_100_000)
            ->and($byTreatment['exempt']->net_lbp)->toBe(9_000_000)
            ->and($byTreatment['exempt']->vat_lbp)->toBe(0)
            ->and($byTreatment['zero_rated']->net_lbp)->toBe(6_000_000);

        // And the return classifies them correctly.
        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        expect($return->taxable_sales_lbp)->toBe(16_000_000)  // standard + zero-rated
            ->and($return->exempt_sales_lbp)->toBe(9_000_000)
            ->and($return->output_vat_lbp)->toBe(1_100_000);
    });
});

it('keeps an invoice\'s LBP figures equal to the transactions it wrote', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = draftInvoice([
            ['qty' => 1000, 'price' => 3_333_333, 'treatment' => VatTreatment::Standard],
            ['qty' => 1000, 'price' => 1_111_111, 'treatment' => VatTreatment::Exempt],
        ], ['currency' => Currency::USD, 'lbp_per_usd' => 89_500]);

        $issued = app(IssueInvoice::class)->issue($invoice);

        // An invoice that disagrees with what it declared is the contradiction
        // this module exists to prevent.
        expect($issued->net_lbp)->toBe((int) VatTransaction::sum('net_lbp'))
            ->and($issued->vat_lbp)->toBe((int) VatTransaction::sum('vat_lbp'));
    });
});

it('converts a USD invoice to LBP for the return at the invoice rate', function () {
    vatSchedule();

    $this->tenant->run(function () {
        // $500.00 net at 89,500 = 44,750,000 LBP; VAT $55.00 = 4,922,500 LBP
        $invoice = draftInvoice(
            [['qty' => 1000, 'price' => 50_000]],
            ['currency' => Currency::USD, 'lbp_per_usd' => 89_500],
        );

        $issued = app(IssueInvoice::class)->issue($invoice);

        expect($issued->subtotal_minor)->toBe(50_000)   // $500.00
            ->and($issued->vat_minor)->toBe(5_500)      // $55.00
            ->and($issued->net_lbp)->toBe(44_750_000)
            ->and($issued->vat_lbp)->toBe(4_922_500);

        expect(VatTransaction::sole()->vat_lbp)->toBe(4_922_500);
    });
});

it('removes the VAT transaction when an invoice is voided', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = app(IssueInvoice::class)->issue(draftInvoice(), Carbon::parse('2026-02-10'));
        expect(VatTransaction::count())->toBe(1);

        $invoice->void('Duplicate');

        expect(VatTransaction::count())->toBe(0)
            ->and($invoice->fresh()->status)->toBe('void')
            ->and($invoice->fresh()->vatTransactions()->count())->toBe(0);

        // ...and the return no longer counts it.
        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);
        expect($return->output_vat_lbp)->toBe(0);
    });
});

it('will not void an invoice already included in a filed return', function () {
    vatVerified();

    $this->tenant->run(function () {
        $invoice = app(IssueInvoice::class)->issue(draftInvoice(), Carbon::parse('2026-02-10'));
        app(PrepareVatReturn::class)->forQuarter(2026, 1)->finalise();

        // Voiding would silently alter a period already declared.
        expect(fn () => $invoice->fresh()->void('Oops'))
            ->toThrow(InvoicingException::class, 'already included in a filed VAT return');

        expect(VatReturn::sole()->output_vat_lbp)->toBe(110_000);
    });
});

it('will not void a draft', function () {
    vatSchedule();

    $this->tenant->run(function () {
        expect(fn () => draftInvoice()->void('nope'))
            ->toThrow(InvoicingException::class, 'never issued');
    });
});

// -- Lifecycle --------------------------------------------------------------

it('marks an issued invoice paid', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = app(IssueInvoice::class)->issue(draftInvoice());
        $invoice->markPaid();

        expect($invoice->fresh()->status)->toBe('paid')
            ->and($invoice->fresh()->paid_at)->not->toBeNull()
            ->and($invoice->fresh()->statusKey())->toBe('filed');
    });
});

it('will not mark a draft paid', function () {
    vatSchedule();

    $this->tenant->run(function () {
        expect(fn () => draftInvoice()->markPaid())
            ->toThrow(InvoicingException::class, 'not issued');
    });
});

it('reports an overdue invoice', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = app(IssueInvoice::class)->issue(
            draftInvoice(), Carbon::parse('2026-01-01'), dueInDays: 30
        );

        expect($invoice->due_on->toDateString())->toBe('2026-01-31')
            ->and($invoice->isOverdue())->toBeTrue()
            ->and($invoice->statusKey())->toBe('overdue');
    });
});

it('keeps one business\'s invoices out of another\'s', function () {
    vatSchedule();
    $globex = makeTenant('globex');

    $this->tenant->run(fn () => app(IssueInvoice::class)->issue(draftInvoice()));

    $globex->run(function () {
        expect(Invoice::count())->toBe(0)
            ->and(Customer::count())->toBe(0);
    });
});
