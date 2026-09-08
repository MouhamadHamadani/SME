<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeRequest;
use App\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class EmployeeController extends Controller
{
    public function index(): View
    {
        return view('app.employees.index', [
            'active' => Employee::whereNull('ended_on')->orderBy('name')->get(),
            'former' => Employee::whereNotNull('ended_on')->orderByDesc('ended_on')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('manage-payroll');

        return view('app.employees.form', ['employee' => new Employee]);
    }

    public function store(EmployeeRequest $request): RedirectResponse
    {
        Employee::create($request->toAttributes());

        return redirect()
            ->route('app.employees.index')
            ->with('status', __('app.employees.saved'));
    }

    public function edit(Employee $employee): View
    {
        $this->authorize('manage-payroll');

        return view('app.employees.form', ['employee' => $employee]);
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $employee->update($request->toAttributes());

        return redirect()
            ->route('app.employees.index')
            ->with('status', __('app.employees.saved'));
    }

    /**
     * People are never deleted — they are ended.
     *
     * A finalised payroll line points at this row, and an employee who left in
     * March must still appear on March's declaration. `payroll_lines` restricts
     * the delete at the database level too, so this is belt and braces.
     */
    public function end(Employee $employee): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $employee->update(['ended_on' => $employee->ended_on ?? now()->toDateString()]);

        return redirect()
            ->route('app.employees.index')
            ->with('status', __('app.employees.ended', ['name' => $employee->name]));
    }
}
