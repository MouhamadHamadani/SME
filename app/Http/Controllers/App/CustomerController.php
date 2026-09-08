<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(): View
    {
        return view('app.customers.index', [
            'customers' => Customer::withCount('invoices')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('manage-payroll');

        return view('app.customers.form', ['customer' => new Customer]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-payroll');

        Customer::create($this->validated($request));

        return redirect()->route('app.customers.index')->with('status', __('app.customers.saved'));
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('manage-payroll');

        return view('app.customers.form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $customer->update($this->validated($request));

        return redirect()->route('app.customers.index')->with('status', __('app.customers.saved'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'vat_number' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
