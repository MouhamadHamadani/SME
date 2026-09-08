<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\NssfRateSchedule;
use App\Models\PayrollRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

class PayrollController extends Controller
{
    /**
     * The latest payroll run, or an honest empty state.
     *
     * Three distinct states, because they need three different answers:
     *   no rate schedule -> we cannot compute, and will not pretend to
     *   no runs yet      -> nothing drafted
     *   a run            -> the numbers, flagged if the rates are unverified
     */
    public function __invoke(): View
    {
        $run = PayrollRun::with('lines')
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first();

        $schedule = NssfRateSchedule::inForceOn(Carbon::now());

        return view('app.payroll', [
            'run' => $run,
            'schedule' => $schedule,
            'employeeCount' => Employee::whereNull('ended_on')->count(),
            // A run is only trustworthy if the schedule it used was verified.
            'ratesVerified' => $run
                ? (bool) NssfRateSchedule::find($run->nssf_rate_schedule_id)?->isVerified()
                : false,
        ]);
    }
}
