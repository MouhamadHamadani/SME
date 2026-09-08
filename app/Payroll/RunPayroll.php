<?php

namespace App\Payroll;

use App\Models\Employee;
use App\Models\NssfRateSchedule;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Payroll\Exceptions\PayrollException;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Build (or rebuild) one month's payroll.
 *
 * Re-running a draft month replaces its lines rather than duplicating them, so
 * correcting a salary and running again is safe. A finalised month is immutable.
 */
class RunPayroll
{
    public function __construct(private readonly NssfCalculator $calculator) {}

    public function forMonth(int $year, int $month, int $lbpPerUsd): PayrollRun
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $label = sprintf('%04d-%02d', $year, $month);

        $existing = PayrollRun::where('period_year', $year)->where('period_month', $month)->first();

        if ($existing?->isFinalised()) {
            throw PayrollException::alreadyFinalised($label);
        }

        /*
         * Contributions are computed against the rules in force during the
         * period being paid — not today's. That is what makes a re-run of an
         * old month reproduce its original numbers.
         */
        $schedule = NssfRateSchedule::inForceOn($periodEnd);

        if (! $schedule) {
            throw PayrollException::noRateSchedule($label);
        }

        $rates = $schedule->rates;

        $employees = Employee::activeDuring($periodStart, $periodEnd)->orderBy('name')->get();

        if ($employees->isEmpty()) {
            throw PayrollException::noEmployees($label);
        }

        /*
         * One transaction: a payroll run that half-wrote its lines is worse
         * than one that failed outright. This is why the tenant databases run
         * on InnoDB — MyISAM would silently leave the partial write in place.
         */
        return DB::transaction(function () use (
            $existing, $year, $month, $lbpPerUsd, $schedule, $rates, $employees
        ) {
            $run = $existing ?? new PayrollRun;

            $run->fill([
                'period_year' => $year,
                'period_month' => $month,
                'lbp_per_usd' => $lbpPerUsd,
                'nssf_rate_schedule_id' => $schedule->id,
                'status' => 'draft',
                'finalised_at' => null,
                ...$rates->toArray(),
            ])->save();

            // Rebuild rather than upsert: an employee who left should vanish
            // from the redraft, not linger with stale figures.
            $run->lines()->delete();

            foreach ($employees as $employee) {
                $grossLbp = $employee->baseSalary()->convertTo(Currency::LBP, $lbpPerUsd);

                $breakdown = $employee->is_nssf_registered
                    ? $this->calculator->calculate($grossLbp, $rates)
                    : $this->calculator->calculateUnregistered($grossLbp);

                $run->lines()->create(PayrollLine::fromBreakdown($breakdown, $employee));
            }

            return $run->fresh('lines');
        });
    }
}
