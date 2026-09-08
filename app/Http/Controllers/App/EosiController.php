<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePayElement;
use App\Payroll\Eosi\AccrueEosi;
use App\Payroll\Eosi\EosiStrategy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One employee's end-of-service estimate.
 *
 * Viewing is open to any signed-in user — this is a figure the business needs
 * to plan around and, eventually, to show the employee. Changing anything that
 * feeds it is gated on `manage-payroll`, like every other write to salary data.
 */
class EosiController extends Controller
{
    public function show(Employee $employee, AccrueEosi $accruals, EosiStrategy $strategy): View
    {
        $employee->load('payElements', 'eosiAccruals');

        return view('app.employees.eosi', [
            'employee' => $employee,
            // Computed live so the screen is never stale; the stored accruals
            // below are the historical record, which is a different thing.
            'estimate' => $accruals->estimate($employee),
            'strategy' => $strategy,
            'accruals' => $employee->eosiAccruals,
            'canManage' => request()->user()->can('manage-payroll'),
        ]);
    }

    /** Freeze today's estimate into the accrual ledger. */
    public function accrue(Employee $employee, AccrueEosi $accruals): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $accruals->accrue($employee);

        return redirect()
            ->route('app.employees.eosi', $employee)
            ->with('status', __('app.eosi.recorded'));
    }

    public function storePayElement(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', EmployeePayElement::KINDS)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $currency = $employee->base_salary_currency;

        $employee->payElements()->create([
            'kind' => $data['kind'],
            // Minor units, via the same integer path as every other amount.
            'amount_minor' => (int) round(((float) $data['amount']) * $currency->minorUnitsPerMajor()),
            'amount_currency' => $currency,
            'paid_on' => Carbon::parse($data['paid_on']),
            'note' => $data['note'] ?? null,
        ]);

        return redirect()
            ->route('app.employees.eosi', $employee)
            ->with('status', __('app.eosi.element_added'));
    }

    public function destroyPayElement(Employee $employee, EmployeePayElement $payElement): RedirectResponse
    {
        $this->authorize('manage-payroll');

        abort_unless($payElement->employee_id === $employee->id, 404);

        $payElement->delete();

        return redirect()
            ->route('app.employees.eosi', $employee)
            ->with('status', __('app.eosi.element_removed'));
    }
}
