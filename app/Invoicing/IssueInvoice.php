<?php

namespace App\Invoicing;

use App\Invoicing\Exceptions\InvoicingException;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\VatRateSchedule;
use App\Models\VatTransaction;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turn a draft into a tax invoice.
 *
 * Three things happen together, or none of them do:
 *   1. the invoice takes the next number in the series,
 *   2. its totals are locked against the VAT rate in force that day,
 *   3. it writes the vat_transactions rows the VAT return will read.
 *
 * Step 3 is the point of the module. The return has exactly one source of
 * truth; invoicing feeds it rather than the return learning to read two
 * places (see docs/VAT.md).
 */
class IssueInvoice
{
    public function __construct(private readonly CalculateInvoiceTotals $totals) {}

    public function issue(Invoice $invoice, ?Carbon $issuedOn = null, ?int $dueInDays = null): Invoice
    {
        if (! $invoice->isEditable()) {
            throw InvoicingException::notEditable($invoice->label());
        }

        $invoice->load('lines', 'customer');

        if ($invoice->lines->isEmpty()) {
            throw InvoicingException::noLines();
        }

        if ($invoice->currency === Currency::USD && ! $invoice->lbp_per_usd) {
            throw InvoicingException::missingExchangeRate();
        }

        $issuedOn ??= Carbon::today();

        $schedule = VatRateSchedule::inForceOn($issuedOn);

        if (! $schedule) {
            throw InvoicingException::noVatSchedule($issuedOn->toDateString());
        }

        return DB::transaction(function () use ($invoice, $issuedOn, $dueInDays, $schedule) {
            $this->totals->for($invoice, $schedule->standard_rate_bps);
            $invoice->refresh();
            $invoice->load('lines', 'customer');

            $number = $this->nextNumber();

            $invoice->forceFill([
                'number' => $number,
                'reference' => $this->reference($number, $issuedOn),
                'status' => 'issued',
                'issued_on' => $issuedOn,
                'due_on' => $dueInDays !== null ? $issuedOn->copy()->addDays($dueInDays) : null,
                'vat_rate_schedule_id' => $schedule->id,
                /*
                 * The Ministry's prescribed rate on the day of issue, snapshotted
                 * beside the rate the books were kept at. §3.6: a filing converts
                 * at the prescribed rate, not the market one.
                 *
                 * Null when no rate was entered for that day, and deliberately
                 * NOT a reason to refuse the invoice — an invoice is a commercial
                 * document that must be issuable today. The refusal belongs where
                 * a filing figure is actually produced; see
                 * ExchangeRate::requireRateFor().
                 */
                'mof_rate_lbp_per_usd' => ExchangeRate::asOf($issuedOn)?->mof_rate_lbp_per_usd,
            ])->save();

            $this->writeVatTransactions($invoice, $issuedOn);

            return $invoice->fresh(['lines', 'customer', 'vatTransactions']);
        });
    }

    /**
     * One VAT transaction PER TREATMENT on the invoice.
     *
     * Not one per invoice. A clinic billing standard-rated consultations
     * alongside exempt procedures on the same invoice must have the exempt
     * amount land in exempt turnover, not taxable — reporting a mixed invoice
     * under a single "dominant" treatment overstates taxable sales by exactly
     * the exempt lines, on a return somebody signs.
     */
    private function writeVatTransactions(Invoice $invoice, Carbon $issuedOn): void
    {
        $netLbp = 0;
        $vatLbp = 0;

        foreach ($invoice->lines->groupBy(fn ($line) => $line->treatment->value) as $treatment => $lines) {
            $net = Money::of((int) $lines->sum('net_minor'), $invoice->currency);
            $vat = Money::of((int) $lines->sum('vat_minor'), $invoice->currency);

            $groupNetLbp = $this->toLbp($net, $invoice);
            $groupVatLbp = $this->toLbp($vat, $invoice);

            VatTransaction::create([
                'direction' => 'sale',
                'treatment' => $treatment,
                'transacted_on' => $issuedOn,
                'description' => __('app.invoices.vat_description', ['reference' => $invoice->reference]),
                'counterparty' => $invoice->customer->name,
                'counterparty_vat_number' => $invoice->customer->vat_number,
                'amount_minor' => $net->minorUnits,
                'amount_currency' => $invoice->currency->value,
                'lbp_per_usd' => $invoice->lbp_per_usd,
                'net_lbp' => $groupNetLbp,
                'vat_lbp' => $groupVatLbp,
                'is_recoverable' => true,
                'invoice_id' => $invoice->id,
            ]);

            $netLbp += $groupNetLbp;
            $vatLbp += $groupVatLbp;
        }

        /*
         * The invoice's LBP figures are the SUM of what it declared, not a
         * separate conversion of its totals. Converting each group rounds
         * independently, so re-converting the whole could differ by a lira —
         * and an invoice that disagrees with the transactions it wrote is
         * exactly the contradiction this module exists to prevent.
         */
        $invoice->forceFill(['net_lbp' => $netLbp, 'vat_lbp' => $vatLbp])->save();
    }

    private function toLbp(Money $amount, Invoice $invoice): int
    {
        if ($invoice->currency === Currency::LBP) {
            return $amount->minorUnits;
        }

        return $amount->convertTo(Currency::LBP, (int) $invoice->lbp_per_usd)->minorUnits;
    }

    /**
     * The next number in the series, gap-free.
     *
     * Locked for update so two people issuing at the same instant cannot read
     * the same maximum. The unique index on `number` is the second line of
     * defence: if a race somehow gets through, it fails loudly rather than
     * producing two invoices with one number.
     *
     * Numbers are never reused, including by a voided invoice — a hole in the
     * series is a question from an inspector, a duplicate is a problem.
     */
    private function nextNumber(): int
    {
        $highest = Invoice::query()->lockForUpdate()->max('number');

        return ((int) $highest) + 1;
    }

    private function reference(int $number, Carbon $issuedOn): string
    {
        return sprintf('INV-%d-%04d', $issuedOn->year, $number);
    }
}
