<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\VatRateSchedule;
use App\Models\VatReturn;
use App\Models\VatTransaction;
use App\Vat\Exceptions\VatException;
use App\Vat\PrepareVatReturn;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VatController extends Controller
{
    public function index(): View
    {
        $return = VatReturn::orderByDesc('period_year')->orderByDesc('period_quarter')->first();

        $now = Carbon::now();
        $defaultQuarter = $now->copy()->subMonthsNoOverflow(3);

        return view('app.vat', [
            'return' => $return,
            'schedule' => VatRateSchedule::inForceOn($now),
            'transactions' => $return
                ? VatTransaction::inPeriod($return->start(), $return->end())
                    ->orderBy('transacted_on')->get()
                : collect(),
            'defaultYear' => $defaultQuarter->year,
            'defaultQuarter' => $defaultQuarter->quarter,
            'ratesVerified' => $return
                ? (bool) VatRateSchedule::find($return->vat_rate_schedule_id)?->isVerified()
                : false,
        ]);
    }

    public function store(Request $request, PrepareVatReturn $prepare): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $validated = $request->validate([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_quarter' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        try {
            $prepare->forQuarter((int) $validated['period_year'], (int) $validated['period_quarter']);
        } catch (VatException $e) {
            // A domain refusal is the user's answer, not a 500.
            return back()->withInput()->withErrors(['period_quarter' => $e->getMessage()]);
        }

        return redirect()->route('app.vat')->with('status', __('app.vat.drafted'));
    }

    public function file(): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $return = VatReturn::orderByDesc('period_year')->orderByDesc('period_quarter')->firstOrFail();

        try {
            $return->finalise();
        } catch (VatException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('app.vat')->with('status', __('app.vat.filed_ok'));
    }
}
