<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditNoteRequest;
use App\Invoicing\Exceptions\InvoicingException;
use App\Invoicing\IssueCreditNote;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class CreditNoteController extends Controller
{
    public function show(CreditNote $creditNote): View
    {
        return view('app.invoices.show', [
            'invoice' => $creditNote->load('lines', 'customer', 'creditedInvoice'),
        ]);
    }

    /**
     * The partial-credit form, pre-filled with the invoice's own lines.
     *
     * Redirects rather than 404s when there is nothing left to credit — the
     * button that got you here is gone by then, so arriving is a stale link,
     * not an error worth a page.
     */
    public function create(Invoice $invoice): View|RedirectResponse
    {
        $this->authorize('manage-payroll');

        if (! in_array($invoice->status, ['issued', 'paid'], true) || $invoice->creditableMinor() <= 0) {
            return redirect()
                ->route('app.invoices.show', $invoice)
                ->withErrors(['issue' => InvoicingException::nothingToCredit($invoice->label())->getMessage()]);
        }

        return view('app.credit-notes.form', ['invoice' => $invoice->load('lines')]);
    }

    /**
     * Issue a credit note from the submitted lines — full or partial.
     *
     * The over-credit ceiling is enforced in IssueCreditNote, after the lines
     * are priced: only then is the total known.
     */
    public function store(CreditNoteRequest $request, Invoice $invoice, IssueCreditNote $issuer): RedirectResponse
    {
        try {
            $note = $issuer->issue($invoice->load('lines'), $request->lineAttributes(), Carbon::today());
        } catch (InvoicingException $e) {
            return back()->withInput()->withErrors(['issue' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.credit-notes.show', $note)
            ->with('status', __('app.credit_notes.issued_ok'));
    }

    /** One-click full credit, straight from the invoice screen. */
    public function storeFull(Invoice $invoice, IssueCreditNote $issuer): RedirectResponse
    {
        $this->authorize('manage-payroll');

        try {
            $note = $issuer->full($invoice->load('lines'), Carbon::today());
        } catch (InvoicingException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.credit-notes.show', $note)
            ->with('status', __('app.credit_notes.issued_ok'));
    }
}
