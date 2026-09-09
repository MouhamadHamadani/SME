<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceRequest;
use App\Invoicing\CalculateInvoiceTotals;
use App\Invoicing\Exceptions\InvoicingException;
use App\Invoicing\IssueInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\VatRateSchedule;
use App\Models\WhatsAppMessage;
use App\WhatsApp\Exceptions\WhatsAppException;
use App\WhatsApp\SendInvoice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(): View
    {
        return view('app.invoices.index', [
            'invoices' => Invoice::with('customer')
                ->orderByRaw('number is null desc')
                ->orderByDesc('number')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('manage-payroll');

        return view('app.invoices.form', [
            'invoice' => new Invoice(['currency' => 'LBP']),
            'customers' => Customer::active()->orderBy('name')->get(),
            'defaultRate' => (int) (tenant()->settings['exchange_rate'] ?? 0),
        ]);
    }

    public function store(InvoiceRequest $request, CalculateInvoiceTotals $totals): RedirectResponse
    {
        $invoice = DB::transaction(function () use ($request, $totals) {
            $invoice = Invoice::create($request->invoiceAttributes() + ['status' => 'draft']);
            $invoice->lines()->createMany($request->lineAttributes());

            $this->recalculate($invoice->fresh('lines'), $totals);

            return $invoice;
        });

        return redirect()
            ->route('app.invoices.edit', $invoice)
            ->with('status', __('app.invoices.saved'));
    }

    public function show(Invoice $invoice): View
    {
        return view('app.invoices.show', [
            'invoice' => $invoice->load('lines', 'customer', 'vatTransactions', 'payments.recordedBy'),
            'messages' => WhatsAppMessage::where('invoice_id', $invoice->id)->latest()->get(),
        ]);
    }

    public function edit(Invoice $invoice): View|RedirectResponse
    {
        $this->authorize('manage-payroll');

        // An issued invoice is the tax record; there is nothing to edit.
        if (! $invoice->isEditable()) {
            return redirect()->route('app.invoices.show', $invoice);
        }

        return view('app.invoices.form', [
            'invoice' => $invoice->load('lines'),
            'customers' => Customer::active()->orderBy('name')->get(),
            'defaultRate' => (int) (tenant()->settings['exchange_rate'] ?? 0),
        ]);
    }

    public function update(InvoiceRequest $request, Invoice $invoice, CalculateInvoiceTotals $totals): RedirectResponse
    {
        if (! $invoice->isEditable()) {
            return back()->withErrors(['lines' => InvoicingException::notEditable($invoice->label())->getMessage()]);
        }

        DB::transaction(function () use ($request, $invoice, $totals) {
            $invoice->update($request->invoiceAttributes());
            // Rebuild rather than diff: a removed line must disappear, and
            // line identity carries no meaning on a draft.
            $invoice->lines()->delete();
            $invoice->lines()->createMany($request->lineAttributes());

            $this->recalculate($invoice->fresh('lines'), $totals);
        });

        return redirect()
            ->route('app.invoices.edit', $invoice)
            ->with('status', __('app.invoices.saved'));
    }

    public function issue(Request $request, Invoice $invoice, IssueInvoice $issuer): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $dueDays = $request->filled('due_days') ? (int) $request->input('due_days') : null;

        try {
            $issuer->issue($invoice, Carbon::today(), $dueDays);
        } catch (InvoicingException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.invoices.show', $invoice)
            ->with('status', __('app.invoices.issued_ok'));
    }

    /**
     * Send an issued invoice to the customer on WhatsApp.
     *
     * Synchronous on purpose: the person clicking wants to know whether it
     * went, and a queued job that fails silently is worse than a slow button.
     * Move it to a job when volume needs it — the delivery row already models
     * a queued state.
     */
    public function sendWhatsApp(Invoice $invoice, SendInvoice $sender): RedirectResponse
    {
        $this->authorize('manage-payroll');

        try {
            $sender->send($invoice, tenant());
        } catch (WhatsAppException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.invoices.show', $invoice)
            ->with('status', __('app.whatsapp.sent_ok'));
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('manage-payroll');

        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'max:200'],
        ]);

        try {
            $invoice->void($validated['void_reason']);
        } catch (InvoicingException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.invoices.show', $invoice)
            ->with('status', __('app.invoices.voided_ok'));
    }

    /**
     * Price a draft against the rate in force today, so the running totals on
     * screen are real. If no schedule exists the draft still saves at zero —
     * issuing is where it is refused, loudly.
     */
    private function recalculate(Invoice $invoice, CalculateInvoiceTotals $totals): void
    {
        $schedule = VatRateSchedule::inForceOn(Carbon::today());

        if ($schedule) {
            $totals->for($invoice, $schedule->standard_rate_bps);
        }
    }
}
