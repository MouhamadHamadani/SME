<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentRequest;
use App\Invoicing\Exceptions\InvoicingException;
use App\Invoicing\RecordPayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\PaymentMethod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class PaymentController extends Controller
{
    /**
     * The record-payment form.
     *
     * The remaining balance is stated HERE, before anything is submitted, for
     * the same reason the credit-note form states what is left to credit: a
     * ceiling somebody only meets by tripping over it is a worse ceiling.
     */
    public function create(Invoice $invoice): View|RedirectResponse
    {
        $this->authorize('manage-payroll');

        // Nothing left to collect: the button that got you here is gone by
        // now, so this is a stale link rather than an error worth a page.
        if ($invoice->balance()->isZero() || ! in_array($invoice->status, ['issued', 'paid'], true)) {
            return redirect()->route('app.invoices.show', $invoice);
        }

        return view('app.payments.form', [
            'invoice' => $invoice->load('customer'),
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function store(PaymentRequest $request, Invoice $invoice, RecordPayment $recorder): RedirectResponse
    {
        try {
            $recorder->record(
                $invoice,
                $request->amount(),
                $request->method(),
                Carbon::parse($request->input('received_on')),
                $request->input('reference'),
                $request->input('notes'),
                $request->user(),
            );
        } catch (InvoicingException $e) {
            // Nothing was written — RecordPayment runs in one transaction — so
            // the form comes back with what was typed and the exact ceiling.
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.invoices.show', $invoice)
            ->with('status', __('app.payments.recorded_ok'));
    }

    /**
     * The receipt. Read-only, numbered, printable.
     *
     * HTML only, matching the invoice view — see docs/INVOICING.md §8. No PDF
     * and no print stylesheet here either; that gap is tracked for both
     * documents together rather than half-closed for one of them.
     */
    public function show(Payment $payment): View
    {
        return view('app.payments.receipt', [
            'payment' => $payment->load('invoice.customer', 'recordedBy'),
        ]);
    }

    /**
     * Who owes us money.
     *
     * Deliberately not an aging report: no buckets, no statements. Issued
     * invoices with something still outstanding, overdue ones first.
     *
     * The balance nets credit notes and payments per row, so the filter runs
     * in PHP rather than SQL. That is a real ceiling and an honest one at an
     * SME's invoice count; the fix, if it ever bites, is a stored balance
     * column maintained by RecordPayment, not a cleverer query here.
     */
    public function outstanding(): View
    {
        $invoices = Invoice::query()
            ->issued()
            ->with('customer')
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->get()
            ->filter(fn (Invoice $invoice) => ! $invoice->balance()->isZero())
            ->values();

        return view('app.payments.outstanding', [
            'invoices' => $invoices,
            'overdue' => $invoices->filter(fn (Invoice $invoice) => $invoice->isOverdue()),
        ]);
    }
}
