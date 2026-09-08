<?php

use App\Models\Employee;
use App\Models\NssfRateSchedule;
use App\Models\PayrollRun;
use App\Payroll\Exceptions\PayrollException;
use App\Payroll\RunPayroll;

beforeEach(function () {
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
});

it('refuses to run without a rate schedule instead of assuming zeros', function () {
    // The whole point: no rates means no payroll, not a payroll of zeros that
    // looks like it worked.
    $this->tenant->run(function () {
        Employee::factory()->create();

        expect(fn () => app(RunPayroll::class)->forMonth(2026, 3, 89_500))
            ->toThrow(PayrollException::class, 'No NSSF rate schedule covers 2026-03');
    });
});

it('refuses to run when the schedule does not cover the period', function () {
    schedule(['effective_from' => '2026-06-01']);

    $this->tenant->run(function () {
        Employee::factory()->create();

        expect(fn () => app(RunPayroll::class)->forMonth(2026, 3, 89_500))
            ->toThrow(PayrollException::class);
    });
});

it('converts a USD salary to LBP at the run rate before applying NSSF', function () {
    schedule();

    $this->tenant->run(function () {
        Employee::factory()->paidUsd(50_000)->create(['name' => 'Rami']); // $500

        $run = app(RunPayroll::class)->forMonth(2026, 3, 89_500);
        $line = $run->lines->sole();

        // $500 * 89,500 = 44,750,000 LBP
        expect($line->gross_lbp)->toBe(44_750_000)
            // ...and the contract is preserved exactly, not round-tripped.
            ->and($line->contracted_salary_minor)->toBe(50_000)
            ->and($line->contracted_salary_currency->value)->toBe('USD');

        // Capped branches, plus uncapped EOSI on the full 44.75m.
        expect($line->sickness_employer_lbp)->toBe(200_000)
            ->and($line->family_employer_lbp)->toBe(90_000)
            ->and($line->eosi_employer_lbp)->toBe(3_803_750); // 8.5% of 44,750,000
    });
});

it('snapshots the rates onto the run so a later correction cannot rewrite history', function () {
    $s = schedule();

    $this->tenant->run(function () {
        Employee::factory()->paidLbp(1_000_000)->create();
        $run = app(RunPayroll::class)->forMonth(2026, 3, 89_500);

        expect($run->sickness_employer_bps)->toBe(800)
            ->and($run->sickness_ceiling_lbp)->toBe(2_500_000);
    });

    // Someone corrects the central schedule afterwards.
    $s->update(['sickness_employer_bps' => 900, 'sickness_ceiling_lbp' => 9_000_000]);

    $this->tenant->run(function () {
        $run = PayrollRun::first();

        // The finalised past keeps its own rules.
        expect($run->sickness_employer_bps)->toBe(800)
            ->and($run->sickness_ceiling_lbp)->toBe(2_500_000)
            ->and($run->lines->sole()->sickness_employer_lbp)->toBe(80_000);
    });
});

it('uses the rules in force during the period being paid, not today\'s', function () {
    schedule(['effective_from' => '2025-01-01', 'effective_to' => '2025-12-31', 'sickness_employer_bps' => 700]);
    schedule(['effective_from' => '2026-01-01', 'sickness_employer_bps' => 800]);

    $this->tenant->run(function () {
        Employee::factory()->paidLbp(1_000_000)->create();

        $old = app(RunPayroll::class)->forMonth(2025, 6, 89_500);
        expect($old->sickness_employer_bps)->toBe(700)
            ->and($old->lines->sole()->sickness_employer_lbp)->toBe(70_000);

        $new = app(RunPayroll::class)->forMonth(2026, 3, 89_500);
        expect($new->sickness_employer_bps)->toBe(800)
            ->and($new->lines->sole()->sickness_employer_lbp)->toBe(80_000);
    });
});

it('includes undeclared staff on the payroll with no contributions', function () {
    schedule();

    $this->tenant->run(function () {
        Employee::factory()->paidLbp(3_000_000)->create(['name' => 'Declared']);
        Employee::factory()->undeclared()->paidLbp(3_000_000)->create(['name' => 'Undeclared']);

        $run = app(RunPayroll::class)->forMonth(2026, 3, 89_500);
        $undeclared = $run->lines->firstWhere('employee_name', 'Undeclared');

        expect($run->lines)->toHaveCount(2)
            ->and($undeclared->gross_lbp)->toBe(3_000_000)
            ->and($undeclared->employerContributions()->isZero())->toBeTrue()
            ->and($undeclared->net()->minorUnits)->toBe(3_000_000);
    });
});

it('excludes employees who had not started or had already left', function () {
    schedule();

    $this->tenant->run(function () {
        Employee::factory()->create(['name' => 'Current', 'started_on' => '2020-01-01']);
        Employee::factory()->create(['name' => 'Future', 'started_on' => '2026-09-01']);
        Employee::factory()->create(['name' => 'Left', 'started_on' => '2020-01-01', 'ended_on' => '2026-01-31']);
        // Leaves mid-month — still paid for the part-month they worked.
        Employee::factory()->create(['name' => 'Leaving', 'started_on' => '2020-01-01', 'ended_on' => '2026-03-10']);

        $run = app(RunPayroll::class)->forMonth(2026, 3, 89_500);

        expect($run->lines->pluck('employee_name')->sort()->values()->all())
            ->toBe(['Current', 'Leaving']);
    });
});

it('rebuilds a draft rather than duplicating it when re-run', function () {
    schedule();

    $this->tenant->run(function () {
        $employee = Employee::factory()->paidLbp(1_000_000)->create();

        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
        $employee->update(['base_salary_minor' => 2_000_000]);
        $run = app(RunPayroll::class)->forMonth(2026, 3, 89_500);

        expect(PayrollRun::count())->toBe(1)
            ->and($run->lines)->toHaveCount(1)
            ->and($run->lines->sole()->gross_lbp)->toBe(2_000_000);
    });
});

it('will not finalise against unverified rates', function () {
    schedule(); // not verified

    $this->tenant->run(function () {
        Employee::factory()->create();
        $run = app(RunPayroll::class)->forMonth(2026, 3, 89_500);

        // Drafting is fine — you can see the numbers.
        expect($run->status)->toBe('draft');

        // Committing to them is not.
        expect(fn () => $run->finalise())
            ->toThrow(PayrollException::class, 'not been verified');
    });
});

it('finalises once the rates are verified', function () {
    verified();

    $this->tenant->run(function () {
        Employee::factory()->create();
        $run = app(RunPayroll::class)->forMonth(2026, 3, 89_500);
        $run->finalise();

        expect($run->fresh()->status)->toBe('finalised')
            ->and($run->fresh()->finalised_at)->not->toBeNull();
    });
});

it('will not re-run or re-finalise a finalised month', function () {
    verified();

    $this->tenant->run(function () {
        Employee::factory()->create();
        $run = app(RunPayroll::class)->forMonth(2026, 3, 89_500);
        $run->finalise();

        expect(fn () => app(RunPayroll::class)->forMonth(2026, 3, 89_500))
            ->toThrow(PayrollException::class, 'cannot be changed');

        expect(fn () => $run->fresh()->finalise())
            ->toThrow(PayrollException::class, 'cannot be changed');
    });
});

it('totals the run for the declaration', function () {
    schedule();

    $this->tenant->run(function () {
        Employee::factory()->paidLbp(1_000_000)->create();
        Employee::factory()->paidLbp(1_000_000)->create();

        $run = app(RunPayroll::class)->forMonth(2026, 3, 89_500);

        expect($run->totalGross()->minorUnits)->toBe(2_000_000)
            ->and($run->totalEmployeeDeductions()->minorUnits)->toBe(60_000)
            ->and($run->totalEmployerContributions()->minorUnits)->toBe(450_000)
            ->and($run->totalEmployerCost()->minorUnits)->toBe(2_450_000)
            ->and($run->totalNet()->minorUnits)->toBe(1_940_000);
    });
});

it('keeps one business\'s payroll out of another\'s', function () {
    schedule();
    $globex = makeTenant('globex');

    $this->tenant->run(function () {
        Employee::factory()->paidLbp(1_000_000)->create(['name' => 'Acme person']);
        app(RunPayroll::class)->forMonth(2026, 3, 89_500);
    });

    $globex->run(function () {
        expect(Employee::count())->toBe(0)
            ->and(PayrollRun::count())->toBe(0);
    });
});

it('reads the rate schedule from the central database while inside a tenant', function () {
    // The schedule is central; tenancy repoints the default connection. If
    // NssfRateSchedule were not pinned, this would look for the table inside
    // the tenant's own database and fail.
    schedule();

    $this->tenant->run(function () {
        expect(NssfRateSchedule::count())->toBe(1);
    });
});
