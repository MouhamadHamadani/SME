<?php

namespace App\Invoicing;

use App\Invoicing\Exceptions\InvoicingException;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\VatRateSchedule;
use App\Models\VatTransaction;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raise and issue a credit note against an issued invoice.
 *
 * The important property: the credit lands in the period the CREDIT NOTE is
 * issued, not the invoice's. A filed VAT return is never reopened — that is
 * the entire reason this exists rather than extending `void`.
 *
 * Amounts are stored POSITIVE on the document (it reads "credit note for
 * 5,000,000") and written NEGATIVE to vat_transactions, so the return's
 * existing sums reduce output VAT with no parallel code path.
 */
class IssueCreditNote
{
    public function __construct(private readonly CalculateInvoiceTotals $totals) {}

    /**
     * Credit an invoice in full: every line copied as-is.
     */
    public function full(Invoice $invoice, ?Carbon $issuedOn = null): CreditNote
    {
        $this->assertCreditable($invoice);

        $lines = $invoice->lines->map(fn ($line) => [
            'position' => $line->position,
            'description' => $line->description,
            'quantity_milli' => $line->quantity_milli,
            'unit_price_minor' => $line->unit_price_minor,
            'treatment' => $line->treatment,
            'net_minor' => 0,
            'vat_minor' => 0,
        ])->all();

        return $this->issue($invoice, $lines, $issuedOn);
    }

    /**
     * Credit part of an invoice — the caller supplies the lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function issue(Invoice $invoice, array $lines, ?Carbon $issuedOn = null): CreditNote
    {
        $this->assertCreditable($invoice);

        if ($lines === []) {
            throw InvoicingException::noLines();
        }

        $issuedOn ??= Carbon::today();

        // The rate in force when the CREDIT NOTE is issued, not the invoice's.
        // A credit raised after a rate change is declared under today's rules.
        $schedule = VatRateSchedule::inForceOn($issuedOn);

        if (! $schedule) {
            throw InvoicingException::noVatSchedule($issuedOn->toDateString());
        }

        return DB::transaction(function () use ($invoice, $lines, $issuedOn, $schedule) {
            $note = CreditNote::create([
                'customer_id' => $invoice->customer_id,
                'credited_invoice_id' => $invoice->id,
                'status' => 'draft',
                'currency' => $invoice->currency,
                'lbp_per_usd' => $invoice->lbp_per_usd,
                'notes' => $invoice->notes,
            ]);

            $note->lines()->createMany($lines);
            $note->refresh();
            $note->load('lines');

            $this->totals->for($note, $schedule->standard_rate_bps);
            $note->refresh();

            // Only now can we tell whether it over-credits.
            $remaining = $invoice->creditableMinor();

            if ($remaining <= 0) {
                throw InvoicingException::nothingToCredit($invoice->label());
            }

            if ($note->total_minor > $remaining) {
                throw InvoicingException::overCrediting(
                    $invoice->label(),
                    Money::of($remaining, $invoice->currency)->format().' '.$invoice->currency->value,
                );
            }

            $number = $this->nextNumber();

            $note->forceFill([
                'number' => $number,
                'reference' => sprintf('CN-%d-%04d', $issuedOn->year, $number),
                'status' => 'issued',
                'issued_on' => $issuedOn,
                'vat_rate_schedule_id' => $schedule->id,
            ])->save();

            $note->load('lines', 'customer');
            $this->writeVatTransactions($note, $issuedOn);

            return $note->fresh(['lines', 'customer', 'vatTransactions']);
        });
    }

    private function assertCreditable(Invoice $invoice): void
    {
        // Only a real, live invoice. A draft should be edited; a void one has
        // already been reversed; a credit note is not itself creditable.
        if (! in_array($invoice->status, ['issued', 'paid'], true)) {
            throw InvoicingException::cannotCredit($invoice->label());
        }

        if ($invoice->creditableMinor() <= 0) {
            throw InvoicingException::nothingToCredit($invoice->label());
        }
    }

    /**
     * One NEGATIVE VAT transaction per treatment, same split as an invoice.
     *
     * Mirrors IssueInvoice::writeVatTransactions — a mixed credit note has to
     * reduce taxable and exempt turnover separately, or it corrects the total
     * while corrupting the classification.
     */
    private function writeVatTransactions(CreditNote $note, Carbon $issuedOn): void
    {
        $netLbp = 0;
        $vatLbp = 0;

        foreach ($note->lines->groupBy(fn ($line) => $line->treatment->value) as $treatment => $lines) {
            $net = Money::of((int) $lines->sum('net_minor'), $note->currency);
            $vat = Money::of((int) $lines->sum('vat_minor'), $note->currency);

            $groupNetLbp = -$this->toLbp($net, $note);
            $groupVatLbp = -$this->toLbp($vat, $note);

            VatTransaction::create([
                'direction' => 'sale',
                'treatment' => $treatment,
                'transacted_on' => $issuedOn,
                'description' => __('app.credit_notes.vat_description', [
                    'reference' => $note->reference,
                    'invoice' => $note->creditedInvoice->reference,
                ]),
                'counterparty' => $note->customer->name,
                'counterparty_vat_number' => $note->customer->vat_number,
                'amount_minor' => -$net->minorUnits,
                'amount_currency' => $note->currency->value,
                'lbp_per_usd' => $note->lbp_per_usd,
                'net_lbp' => $groupNetLbp,
                'vat_lbp' => $groupVatLbp,
                'is_recoverable' => true,
                'invoice_id' => $note->id,
            ]);

            $netLbp += $groupNetLbp;
            $vatLbp += $groupVatLbp;
        }

        // Stored positive on the document, negative in the ledger.
        $note->forceFill(['net_lbp' => abs($netLbp), 'vat_lbp' => abs($vatLbp)])->save();
    }

    private function toLbp(Money $amount, CreditNote $note): int
    {
        if ($note->currency === Currency::LBP) {
            return $amount->minorUnits;
        }

        return $amount->convertTo(Currency::LBP, (int) $note->lbp_per_usd)->minorUnits;
    }

    /** Its own series, independent of invoices. */
    private function nextNumber(): int
    {
        return ((int) CreditNote::query()->lockForUpdate()->max('number')) + 1;
    }
}
