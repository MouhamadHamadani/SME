<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\NssfRateSchedule;
use App\Models\PayrollRun;
use App\Payroll\Exceptions\PayrollException;
use App\Payroll\RunPayroll;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PayrollController extends Controller
{
    /**
     * Draft (or redraft) a month from the app.
     *
     * Deliberately does not finalise: locking a period is a separate,
     * considered act that also requires verified rates.
     */
    public function store(Request $request, RunPayroll $runPayroll): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $validated = $request->validate([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            // Whole LBP per USD, as quoted. Never a decimal.
            'lbp_per_usd' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $runPayroll->forMonth(
                (int) $validated['period_year'],
                (int) $validated['period_month'],
                (int) $validated['lbp_per_usd'],
            );
        } catch (PayrollException $e) {
            // Domain refusals are the user's answer, not a 500.
            return back()->withInput()->withErrors(['period_month' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.payroll')
            ->with('status', __('app.payroll.drafted'));
    }

    public function finalise(RunPayroll $runPayroll): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $run = PayrollRun::orderByDesc('period_year')->orderByDesc('period_month')->firstOrFail();

        try {
            $run->finalise();
        } catch (PayrollException $e) {
            return back()->withErrors(['finalise' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.payroll')
            ->with('status', __('app.payroll.finalised_ok'));
    }

    /**
     * The latest payroll run, or an honest empty state.
     *
     * Three distinct states, because they need three different answers:
     *   no rate schedule -> we cannot compute, and will not pretend to
     *   no runs yet      -> nothing drafted
     *   a run            -> the numbers, flagged if the rates are unverified
     */
    public function index(): View
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
            // Default the form to last month — payroll is run in arrears.
            'defaultPeriod' => Carbon::now()->subMonthNoOverflow(),
            'defaultRate' => (int) (tenant()->settings['exchange_rate'] ?? 0),
            // A run is only trustworthy if the schedule it used was verified.
            'ratesVerified' => $run
                ? (bool) NssfRateSchedule::find($run->nssf_rate_schedule_id)?->isVerified()
                : false,
        ]);
    }
}
