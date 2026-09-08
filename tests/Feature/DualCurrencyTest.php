<?php

use App\Invoicing\IssueInvoice;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Currency;
use App\Support\Money;
use App\Support\RateBasis;
use App\Vat\VatTreatment;
use Illuminate\Support\Carbon;

/*
 | Lebanon has TWO official answers to what a dollar is worth, used for
 | different purposes. These tests pin that they stay two.
 */

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($this->tenant, ['email' => 'owner@acme.test', 'role' => 'owner', 'locale' => 'en']);
});

function rateDay(array $overrides = []): ExchangeRate
{
    return ExchangeRate::create(array_merge([
        'rate_date' => '2026-02-10',
        'market_rate_lbp_per_usd' => 89_500,
        'mof_rate_lbp_per_usd' => 15_000,
        'source' => 'Test fixture — not a claim about any real rate.',
        'verified_at' => now(),
        'verified_by' => 'Test',
    ], $overrides));
}

function asRateUser(string $role = 'owner'): User
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

// -- The two rates are independent -----------------------------------------

it('tracks the market rate and the Ministry rate as genuinely separate values', function () {
    $rate = rateDay();

    expect($rate->rateFor(RateBasis::Market))->toBe(89_500)
        ->and($rate->rateFor(RateBasis::Mof))->toBe(15_000);

    // Moving one must not move the other.
    $rate->update(['market_rate_lbp_per_usd' => 92_000]);
    $rate->refresh();

    expect($rate->market_rate_lbp_per_usd)->toBe(92_000)
        ->and($rate->mof_rate_lbp_per_usd)->toBe(15_000);

    $rate->update(['mof_rate_lbp_per_usd' => 45_000]);
    $rate->refresh();

    expect($rate->mof_rate_lbp_per_usd)->toBe(45_000)
        ->and($rate->market_rate_lbp_per_usd)->toBe(92_000);
});

it('never falls back from one rate to the other', function () {
    // A day with only a market rate means the Ministry rate is UNKNOWN, not
    // that the two are equal. Guessing here invents a filing figure.
    $rate = rateDay(['mof_rate_lbp_per_usd' => null, 'verified_at' => null]);

    expect($rate->rateFor(RateBasis::Mof))->toBeNull()
        ->and($rate->hasBoth())->toBeFalse()
        ->and($rate->spreadBps())->toBeNull();

    expect(fn () => $rate->requireRateFor(RateBasis::Mof))
        ->toThrow(RuntimeException::class, 'No mof rate recorded');
});

it('refuses an unverified Ministry rate', function () {
    $rate = rateDay(['verified_at' => null, 'verified_by' => null]);

    // The market rate is the business's own business.
    expect($rate->requireRateFor(RateBasis::Market))->toBe(89_500);

    // The Ministry rate feeds filings, so it carries the same human gate as
    // the NSSF and VAT schedules.
    expect(fn () => $rate->requireRateFor(RateBasis::Mof))
        ->toThrow(RuntimeException::class, 'has not been verified');
});

it('reports the gap between the two rates', function () {
    // Market 89,500 against a prescribed 15,000 is nearly 5x.
    expect(rateDay()->spreadBps())->toBe(49_667);
});

// -- A USD transaction is never silently reported at the wrong rate --------

it('records a USD invoice with BOTH rates, and they convert to different figures', function () {
    vatSchedule();
    rateDay();

    $this->tenant->run(function () {
        $invoice = draftInvoice(
            [['qty' => 1000, 'price' => 100_00, 'treatment' => VatTreatment::Standard]],
            ['currency' => Currency::USD, 'lbp_per_usd' => 89_500],
        );

        $issued = app(IssueInvoice::class)->issue($invoice, Carbon::parse('2026-02-10'));

        // The transaction's OWN currency survives.
        expect($issued->currency)->toBe(Currency::USD)
            ->and($issued->total()->currency)->toBe(Currency::USD);

        // Both rates are on the document: the one the books were kept at, and
        // the one a filing must use.
        expect($issued->lbp_per_usd)->toBe(89_500)
            ->and($issued->mof_rate_lbp_per_usd)->toBe(15_000);

        /*
         * The stored LBP figure is the BOOKS figure, at the market rate. A
         * filing figure is a different number and has to be computed from the
         * Ministry rate deliberately — it is not what is sitting in net_lbp.
         */
        $booksLbp = $issued->total()->convertTo(Currency::LBP, $issued->lbp_per_usd);
        $filingLbp = $issued->total()->convertTo(Currency::LBP, $issued->mof_rate_lbp_per_usd);

        expect($booksLbp->minorUnits)->toBe(9_934_500)   // 111.00 USD x 89,500
            ->and($filingLbp->minorUnits)->toBe(1_665_000) // 111.00 USD x 15,000
            ->and($booksLbp->equals($filingLbp))->toBeFalse();

        // And the stored lira total is the books one, not the filing one —
        // reporting net_lbp to the Ministry would be the bug this guards.
        expect($issued->net_lbp)->toBe(8_950_000)
            ->and($issued->net_lbp)->not->toBe(1_500_000);
    });
});

it('leaves the Ministry rate null when none was recorded, rather than guessing', function () {
    vatSchedule();
    // No ExchangeRate row at all.

    $this->tenant->run(function () {
        $invoice = draftInvoice(null, ['currency' => Currency::USD, 'lbp_per_usd' => 89_500]);
        $issued = app(IssueInvoice::class)->issue($invoice, Carbon::parse('2026-02-10'));

        // Issuing still works — an invoice is a commercial document that has
        // to be issuable today. The refusal belongs at the filing.
        expect($issued->status)->toBe('issued')
            ->and($issued->mof_rate_lbp_per_usd)->toBeNull();
    });
});

it('carries the last known rate forward but never interpolates one', function () {
    rateDay(['rate_date' => '2026-02-10']);

    expect(ExchangeRate::asOf(Carbon::parse('2026-02-12'))?->rate_date->toDateString())
        ->toBe('2026-02-10');

    // Nothing before the first recorded day.
    expect(ExchangeRate::asOf(Carbon::parse('2026-02-01')))->toBeNull();
});

it('still refuses to add LBP to USD', function () {
    // The existing guard, extended rather than worked around.
    expect(fn () => Money::usd(100)->plus(Money::lbp(100)))
        ->toThrow(InvalidArgumentException::class);
});

// -- Screens ---------------------------------------------------------------

it('shows both national rates read-only and the business booking rate', function () {
    rateDay(['rate_date' => Carbon::today()->toDateString()]);
    asRateUser();

    $this->get(tenantUrl('acme', '/settings/exchange-rates'))
        ->assertOk()
        ->assertSee('Market rate')
        ->assertSee('Ministry rate')
        ->assertSee('89,500')
        ->assertSee('15,000')
        // National figures are recorded centrally, not typed by each business.
        ->assertSee('recorded once for everyone');
});

it('lets the owner set the booking rate but not an accountant', function () {
    asRateUser();

    $this->post(tenantUrl('acme', '/settings/exchange-rates'), ['booking_rate' => 91_000])
        ->assertRedirect(tenantUrl('acme', '/settings/exchange-rates'));

    expect((int) $this->tenant->fresh()->settings['exchange_rate'])->toBe(91_000);

    asRateUser('accountant');

    $this->post(tenantUrl('acme', '/settings/exchange-rates'), ['booking_rate' => 1])
        ->assertForbidden();

    expect((int) $this->tenant->fresh()->settings['exchange_rate'])->toBe(91_000);
});

it('values outstanding USD invoices at both rates on the revaluation screen', function () {
    vatSchedule();
    rateDay(['rate_date' => Carbon::today()->toDateString()]);

    $this->tenant->run(function () {
        $invoice = draftInvoice(
            [['qty' => 1000, 'price' => 100_00, 'treatment' => VatTreatment::Standard]],
            ['currency' => Currency::USD, 'lbp_per_usd' => 89_500],
        );
        app(IssueInvoice::class)->issue($invoice, Carbon::today());
    });

    asRateUser();

    $this->get(tenantUrl('acme', '/revaluation'))
        ->assertOk()
        ->assertSee('Outstanding in USD')
        ->assertSee('111.00')
        // 111.00 at market, and the same money at the Ministry rate.
        ->assertSee('9,934,500')
        ->assertSee('1,665,000');
});

it('keeps a paid invoice out of the revaluation', function () {
    vatSchedule();
    rateDay(['rate_date' => Carbon::today()->toDateString()]);

    $this->tenant->run(function () {
        $invoice = draftInvoice(null, ['currency' => Currency::USD, 'lbp_per_usd' => 89_500]);
        $issued = app(IssueInvoice::class)->issue($invoice, Carbon::today());
        $issued->markPaid();
    });

    asRateUser();

    $this->get(tenantUrl('acme', '/revaluation'))
        ->assertOk()
        ->assertSee('No unpaid USD invoices');
});

it('records rates from the command and clears verification when figures change', function () {
    $this->artisan('exchange:rate', [
        '--date' => '2026-03-01',
        '--market' => 90_000,
        '--mof' => 15_000,
        '--source' => 'Test',
    ])->assertSuccessful();

    $rate = ExchangeRate::forDate(Carbon::parse('2026-03-01'));
    expect($rate->market_rate_lbp_per_usd)->toBe(90_000);

    $this->artisan('exchange:rate', ['--verify' => $rate->id, '--by' => 'Rima'])->assertSuccessful();
    expect($rate->fresh()->isVerified())->toBeTrue();

    // Correcting a figure must not keep a verification given for a different
    // number — otherwise a filing rests on a signature nobody gave.
    $this->artisan('exchange:rate', [
        '--date' => '2026-03-01',
        '--mof' => 45_000,
        '--source' => 'Corrected',
    ])->assertSuccessful();

    $rate = $rate->fresh();
    expect($rate->mof_rate_lbp_per_usd)->toBe(45_000)
        ->and($rate->market_rate_lbp_per_usd)->toBe(90_000)
        ->and($rate->isVerified())->toBeFalse();
});
