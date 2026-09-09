<?php

use App\Invoicing\Exceptions\InvoicingException;
use App\Invoicing\IssueCreditNote;
use App\Invoicing\RecordPayment;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Currency;
use App\Support\Money;
use App\Support\PaymentMethod;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
});

/**
 * Record a payment against an invoice. Defaults to LBP cash today.
 *
 * `issuedInvoice()` and `draftInvoice()` come from CreditNoteTest and
 * InvoicingTest — Pest loads every test file, so the fixtures are shared
 * rather than re-declared per suite.
 */
function pay(Invoice $invoice, int $minor, PaymentMethod $method = PaymentMethod::Cash, ?string $on = null, ?Currency $currency = null): Payment
{
    return app(RecordPayment::class)->record(
        $invoice,
        Money::of($minor, $currency ?? $invoice->currency),
        $method,
        $on ? Carbon::parse($on) : null,
    );
}

// -- Partial payment: the thing the old flag could not express ---------------

it('reduces the balance by a partial payment without calling it paid', function () {
    vatSchedule();

    $this->tenant->run(function () {
        // 1,000,000 net + 11% = 1,110,000.
        $invoice = issuedInvoice();
        expect($invoice->total_minor)->toBe(1_110_000);

        pay($invoice, 400_000);
        $invoice = $invoice->fresh();

        expect($invoice->amountPaid()->minorUnits)->toBe(400_000)
            ->and($invoice->balance()->minorUnits)->toBe(710_000)
            ->and($invoice->isPartiallyPaid())->toBeTrue()
            ->and($invoice->isFullyPaid())->toBeFalse()
            // The status flag stays `issued`: it is not settled.
            ->and($invoice->status)->toBe('issued')
            ->and($invoice->paid_at)->toBeNull();
    });
});

it('settles an invoice across two payments in different methods', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();

        pay($invoice, 600_000, PaymentMethod::Cash, '2026-02-12');
        pay($invoice->fresh(), 510_000, PaymentMethod::Cheque, '2026-02-20');

        $invoice = $invoice->fresh();

        // Both are in the history, in the order the money arrived.
        expect($invoice->payments)->toHaveCount(2)
            ->and($invoice->payments->pluck('method')->all())
            ->toBe([PaymentMethod::Cash, PaymentMethod::Cheque])
            // And both count toward the balance.
            ->and($invoice->amountPaid()->minorUnits)->toBe(1_110_000)
            ->and($invoice->balance()->isZero())->toBeTrue()
            ->and($invoice->isFullyPaid())->toBeTrue()
            ->and($invoice->isPartiallyPaid())->toBeFalse()
            // The second payment, the one that zeroed it, flipped the flag.
            ->and($invoice->status)->toBe('paid')
            ->and($invoice->paid_at)->not->toBeNull();
    });
});

// -- The ceiling ------------------------------------------------------------

it('refuses an overpayment, naming the exact balance, and writes nothing', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        pay($invoice, 600_000);

        expect(fn () => pay($invoice->fresh(), 600_001))
            ->toThrow(InvoicingException::class, '510,000 LBP');

        // Nothing partially written: no second payment, no consumed receipt
        // number, and the balance is exactly where it was.
        $invoice = $invoice->fresh();
        expect(Payment::count())->toBe(1)
            ->and(Payment::max('receipt_number'))->toBe(1)
            ->and($invoice->balance()->minorUnits)->toBe(510_000)
            ->and($invoice->status)->toBe('issued');
    });
});

it('refuses a payment once the balance is already zero', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        pay($invoice, 1_110_000);

        expect(fn () => pay($invoice->fresh(), 1))
            ->toThrow(InvoicingException::class, 'nothing left outstanding');
    });
});

// -- Currency ---------------------------------------------------------------

it('refuses a payment in a currency the invoice is not denominated in', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();  // LBP

        expect(fn () => pay($invoice, 100_00, PaymentMethod::Cash, currency: Currency::USD))
            ->toThrow(InvoicingException::class, 'is a LBP invoice and this payment is in USD');

        // Refused, NOT converted at some rate nobody chose.
        expect(Payment::count())->toBe(0)
            ->and($invoice->fresh()->amountPaid()->minorUnits)->toBe(0);
    });
});

it('snapshots the market rate on a USD payment and leaves an LBP one null', function () {
    vatSchedule();

    ExchangeRate::create([
        'rate_date' => '2026-02-12',
        'market_rate_lbp_per_usd' => 89_500,
        'mof_rate_lbp_per_usd' => 15_000,
        'source' => 'Test fixture',
    ]);

    $this->tenant->run(function () {
        $usd = issuedInvoice(
            [['qty' => 1000, 'price' => 10_000]],   // 100.00 + 11% = 111.00
            overrides: ['currency' => Currency::USD, 'lbp_per_usd' => 89_500],
        );

        $payment = pay($usd, 11_100, PaymentMethod::BankTransfer, '2026-02-12', Currency::USD);

        // The MARKET rate — a receipt is not a filing.
        expect($payment->lbp_per_usd)->toBe(89_500)
            ->and($payment->amountLbp()->minorUnits)->toBe(9_934_500);

        $lbp = issuedInvoice();
        expect(pay($lbp, 1_000)->lbp_per_usd)->toBeNull();
    });
});

// -- Credit notes and payments in ONE balance -------------------------------

it('nets a credit note out of the balance alongside payments', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();                     // 1,110,000

        // Credit half of it: 500,000 net + 11% = 555,000.
        app(IssueCreditNote::class)->issue($invoice->fresh('lines'), [[
            'position' => 0, 'description' => 'Half returned', 'quantity_milli' => 1000,
            'unit_price_minor' => 500_000, 'treatment' => \App\Vat\VatTreatment::Standard,
            'net_minor' => 0, 'vat_minor' => 0,
        ]], Carbon::parse('2026-03-01'));

        $invoice = $invoice->fresh();
        expect($invoice->creditedMinor())->toBe(555_000)
            // Credited but not yet paid: the balance already reflects it.
            ->and($invoice->balance()->minorUnits)->toBe(555_000)
            ->and($invoice->isPartiallyPaid())->toBeFalse();

        pay($invoice, 200_000);

        $invoice = $invoice->fresh();
        // 1,110,000 - 555,000 credited - 200,000 paid.
        expect($invoice->balance()->minorUnits)->toBe(355_000);

        // And the ceiling is the NETTED balance, not the invoice total.
        expect(fn () => pay($invoice, 355_001))
            ->toThrow(InvoicingException::class, '355,000 LBP');

        pay($invoice, 355_000);
        expect($invoice->fresh()->isFullyPaid())->toBeTrue()
            ->and($invoice->fresh()->status)->toBe('paid');
    });
});

it('does not call a fully credited invoice paid', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-03-01'));

        $invoice = $invoice->fresh();

        // Nothing is owed, but nobody paid it. Those are different facts.
        expect($invoice->balance()->isZero())->toBeTrue()
            ->and($invoice->isFullyPaid())->toBeFalse()
            ->and($invoice->isPartiallyPaid())->toBeFalse()
            ->and($invoice->status)->toBe('issued');
    });
});

// -- What a payment forbids -------------------------------------------------

it('refuses to void an invoice that has any payment recorded', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();
        pay($invoice, 1);   // one lira is enough: it is a settled transaction

        expect(fn () => $invoice->fresh()->void('Duplicate'))
            ->toThrow(InvoicingException::class, 'has payments recorded against it');

        expect($invoice->fresh()->status)->toBe('issued')
            ->and($invoice->fresh()->vatTransactions()->count())->toBe(1);
    });
});

it('refuses a payment against a draft, a void invoice or a credit note', function () {
    vatSchedule();

    $this->tenant->run(function () {
        expect(fn () => pay(draftInvoice(), 1_000))
            ->toThrow(InvoicingException::class, 'cannot take a payment');

        $void = issuedInvoice();
        $void->void('Cancelled');
        expect(fn () => pay($void->fresh(), 1_000))
            ->toThrow(InvoicingException::class, 'cannot take a payment');

        $invoice = issuedInvoice();
        $note = app(IssueCreditNote::class)->full($invoice->fresh('lines'), Carbon::parse('2026-03-01'));
        expect(fn () => pay($note, 1_000))
            ->toThrow(InvoicingException::class, 'reduces what is owed rather than being paid');
    });
});

it('refuses a zero or negative payment', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice();

        expect(fn () => pay($invoice, 0))->toThrow(InvoicingException::class, 'more than zero');
        expect(fn () => pay($invoice, -500))->toThrow(InvoicingException::class, 'more than zero');
    });
});

// -- Receipt numbering: the same discipline as invoice numbers ---------------

it('numbers receipts sequentially and gap-free across invoices', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $first = issuedInvoice();
        $second = issuedInvoice();

        $a = pay($first, 100_000);
        $b = pay($second, 200_000);
        $c = pay($first->fresh(), 300_000);

        expect([$a->receipt_number, $b->receipt_number, $c->receipt_number])->toBe([1, 2, 3])
            ->and($a->receiptReference())->toBe('RCT-'.now()->year.'-0001')
            ->and($c->receiptReference())->toBe('RCT-'.now()->year.'-0003');

        // A refused payment must not consume a number.
        expect(fn () => pay($first->fresh(), 999_999_999))->toThrow(InvoicingException::class);
        expect(pay($first->fresh(), 10_000)->receipt_number)->toBe(4);
    });
});

// -- The status combination that had no answer before ------------------------

it('shows an overdue, partly paid invoice as overdue and says so in words', function () {
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = issuedInvoice(on: '2026-01-01');
        $invoice->forceFill(['due_on' => '2026-01-31'])->save();

        pay($invoice->fresh(), 400_000, PaymentMethod::Whish);
        $invoice = $invoice->fresh();

        expect($invoice->isOverdue())->toBeTrue()
            ->and($invoice->isPartiallyPaid())->toBeTrue()
            // Red, not amber and not green: a part payment does not cure
            // lateness, and green is reserved for a zero balance.
            ->and($invoice->statusKey())->toBe('overdue')
            // The partial settlement is not lost — it is in the label.
            ->and($invoice->statusLabel())->toBe('Overdue · partly paid');

        // Paying the rest clears both at once.
        pay($invoice, 710_000);
        $invoice = $invoice->fresh();

        expect($invoice->isOverdue())->toBeFalse()
            ->and($invoice->statusKey())->toBe('filed')
            ->and($invoice->statusLabel())->toBe('Paid');
    });
});

// -- Tenancy ----------------------------------------------------------------

it('keeps payments inside their own business', function () {
    vatSchedule();
    $other = makeTenant('beta', ['name' => 'Beta Co']);

    $this->tenant->run(function () {
        pay(issuedInvoice(), 400_000);
    });

    $other->run(function () {
        expect(Payment::count())->toBe(0)
            ->and(Invoice::count())->toBe(0);

        // And its own receipt series starts at 1, independently.
        Customer::factory()->create();
        expect(pay(issuedInvoice(), 100_000)->receipt_number)->toBe(1);
    });

    $this->tenant->run(function () {
        expect(Payment::count())->toBe(1)
            ->and(Payment::sole()->receipt_number)->toBe(1);
    });
});
