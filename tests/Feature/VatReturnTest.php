<?php

use App\Models\VatRateSchedule;
use App\Models\VatReturn;
use App\Models\VatTransaction;
use App\Vat\Exceptions\VatException;
use App\Vat\PrepareVatReturn;
use App\Vat\VatTreatment;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
});

it('refuses to build a return without a rate schedule', function () {
    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-15')->create();

        expect(fn () => app(PrepareVatReturn::class)->forQuarter(2026, 1))
            ->toThrow(VatException::class, 'No VAT rate schedule covers 2026-Q1');
    });
});

it('totals output and input VAT for the quarter', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(10_000_000)->on('2026-02-10')->create();
        VatTransaction::factory()->purchase()->net(4_000_000)->on('2026-02-20')->create();

        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        expect($return->output_vat_lbp)->toBe(1_100_000)   // 11% of 10m
            ->and($return->input_vat_lbp)->toBe(440_000)    // 11% of 4m
            ->and($return->net_position_lbp)->toBe(660_000)
            ->and($return->payable_lbp)->toBe(660_000)
            ->and($return->credit_carried_forward_lbp)->toBe(0);
    });
});

it('keeps exempt sales out of taxable turnover but zero-rated sales in', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000, VatTreatment::Standard)->on('2026-01-05')->create();
        VatTransaction::factory()->sale()->net(2_000_000, VatTreatment::ZeroRated)->on('2026-01-06')->create();
        VatTransaction::factory()->sale()->net(4_000_000, VatTreatment::Exempt)->on('2026-01-07')->create();

        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        // Standard + zero-rated are taxable turnover; exempt is not.
        expect($return->taxable_sales_lbp)->toBe(3_000_000)
            ->and($return->exempt_sales_lbp)->toBe(4_000_000)
            // Only the standard line carries VAT.
            ->and($return->output_vat_lbp)->toBe(110_000);
    });
});

it('does not claim input VAT the bookkeeper marked unrecoverable', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->purchase()->net(1_000_000)->on('2026-01-10')->create();
        VatTransaction::factory()->purchase()->net(1_000_000)->on('2026-01-11')->notRecoverable()->create();

        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        // Both purchases counted as spend; only one claimed.
        expect($return->purchases_lbp)->toBe(2_000_000)
            ->and($return->input_vat_lbp)->toBe(110_000);
    });
});

it('reports a credit rather than a negative payment when input exceeds output', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        VatTransaction::factory()->purchase()->net(5_000_000)->on('2026-01-06')->create();

        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        expect($return->net_position_lbp)->toBe(-440_000)   // 110k - 550k
            ->and($return->payable_lbp)->toBe(0)            // nothing to pay
            ->and($return->credit_carried_forward_lbp)->toBe(440_000)
            ->and($return->isInCredit())->toBeTrue();
    });
});

it('carries a credit forward from the previous finalised quarter', function () {
    vatVerified();

    $this->tenant->run(function () {
        // Q1 ends in credit.
        VatTransaction::factory()->purchase()->net(5_000_000)->on('2026-01-06')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1)->finalise();

        // Q2 owes more than it reclaims, but the Q1 credit reduces it.
        VatTransaction::factory()->sale()->net(10_000_000)->on('2026-04-10')->create();
        $q2 = app(PrepareVatReturn::class)->forQuarter(2026, 2);

        expect($q2->credit_brought_forward_lbp)->toBe(550_000)
            ->and($q2->output_vat_lbp)->toBe(1_100_000)
            ->and($q2->payable_lbp)->toBe(550_000); // 1,100,000 - 0 - 550,000
    });
});

it('does not carry forward from a quarter that is still a draft', function () {
    vatSchedule();

    $this->tenant->run(function () {
        // Q1 is in credit but never finalised — a draft can still change, and
        // building on a moving figure would silently alter Q2 each rebuild.
        VatTransaction::factory()->purchase()->net(5_000_000)->on('2026-01-06')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1);

        VatTransaction::factory()->sale()->net(10_000_000)->on('2026-04-10')->create();
        $q2 = app(PrepareVatReturn::class)->forQuarter(2026, 2);

        expect($q2->credit_brought_forward_lbp)->toBe(0)
            ->and($q2->payable_lbp)->toBe(1_100_000);
    });
});

it('carries a credit across a year boundary', function () {
    vatVerified();

    $this->tenant->run(function () {
        VatTransaction::factory()->purchase()->net(5_000_000)->on('2026-11-06')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 4)->finalise();

        $q1 = app(PrepareVatReturn::class)->forQuarter(2027, 1);

        expect($q1->credit_brought_forward_lbp)->toBe(550_000);
    });
});

it('only counts transactions inside the quarter', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2025-12-31')->create(); // before
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-01')->create(); // first day
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-03-31')->create(); // last day
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-04-01')->create(); // after

        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        expect($return->taxable_sales_lbp)->toBe(2_000_000);
    });
});

it('sets the filing deadline from the schedule', function () {
    vatSchedule(['filing_deadline_days' => 20]);

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-02-01')->create();

        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        // Q1 ends 31 March; +20 days = 20 April.
        expect($return->due_on->toDateString())->toBe('2026-04-20');
    });
});

it('snapshots the rate so a later correction cannot rewrite a filed quarter', function () {
    $s = vatVerified();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1)->finalise();
    });

    $s->update(['standard_rate_bps' => 1500]);

    $this->tenant->run(function () {
        $return = VatReturn::sole();

        expect($return->standard_rate_bps)->toBe(1100)
            ->and($return->output_vat_lbp)->toBe(110_000);
    });
});

it('will not finalise against unverified rates', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        expect($return->status)->toBe('draft');
        expect(fn () => $return->finalise())->toThrow(VatException::class, 'not been verified');
    });
});

it('locks its transactions on finalise so they cannot be claimed twice', function () {
    vatVerified();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);
        $return->finalise();

        expect(VatTransaction::sole()->vat_return_id)->toBe($return->id)
            ->and(VatTransaction::sole()->isLocked())->toBeTrue();
    });
});

it('will not rebuild or re-finalise a filed quarter', function () {
    vatVerified();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);
        $return->finalise();

        expect(fn () => app(PrepareVatReturn::class)->forQuarter(2026, 1))
            ->toThrow(VatException::class, 'cannot be changed');
        expect(fn () => $return->fresh()->finalise())
            ->toThrow(VatException::class, 'cannot be changed');
    });
});

it('rebuilds a draft quarter when transactions change', function () {
    vatSchedule();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1);

        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-06')->create();
        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        expect(VatReturn::count())->toBe(1)
            ->and($return->output_vat_lbp)->toBe(220_000);
    });
});

it('rejects a quarter outside 1-4', function () {
    vatSchedule();

    $this->tenant->run(function () {
        expect(fn () => app(PrepareVatReturn::class)->forQuarter(2026, 5))
            ->toThrow(VatException::class, 'Quarter must be 1-4');
    });
});

it('reports a filing status the badge can render', function () {
    vatVerified();

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        $return = app(PrepareVatReturn::class)->forQuarter(2026, 1);

        // Long past its 2026-04-20 deadline and still a draft.
        expect($return->statusKey())->toBe('overdue');

        $return->finalise();
        expect($return->fresh()->statusKey())->toBe('filed');
    });
});

it('keeps one business\'s VAT out of another\'s', function () {
    vatSchedule();
    $globex = makeTenant('globex');

    $this->tenant->run(function () {
        VatTransaction::factory()->sale()->net(1_000_000)->on('2026-01-05')->create();
        app(PrepareVatReturn::class)->forQuarter(2026, 1);
    });

    $globex->run(function () {
        expect(VatTransaction::count())->toBe(0)
            ->and(VatReturn::count())->toBe(0);
    });
});

it('reads the VAT schedule from the central database while inside a tenant', function () {
    vatSchedule();

    $this->tenant->run(function () {
        expect(VatRateSchedule::count())->toBe(1);
    });
});
