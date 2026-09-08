<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
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
     * Credit an invoice in full.
     *
     * Partial credits exist in the domain (IssueCreditNote::issue takes
     * arbitrary lines) but have no screen yet — see docs/INVOICING.md.
     */
    public function store(Invoice $invoice, IssueCreditNote $issuer): RedirectResponse
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
