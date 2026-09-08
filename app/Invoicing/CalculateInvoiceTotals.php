<?php

namespace App\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Currency;
use App\Support\Money;
use App\Vat\VatCalculator;

/**
 * Recompute an invoice's line amounts and totals.
 *
 * VAT is computed PER LINE and summed, never applied to the subtotal. The two
 * disagree once rounding bites, and per-line is what the printed invoice
 * shows — so it has to be what the totals reconcile against, or the document
 * contradicts itself.
 */
class CalculateInvoiceTotals
{
    public function __construct(private readonly VatCalculator $vat) {}

    public function for(Invoice $invoice, int $standardBps): void
    {
        $currency = $invoice->currency;
        $subtotal = Money::zero($currency);
        $vatTotal = Money::zero($currency);

        foreach ($invoice->lines as $line) {
            $net = Money::of(
                InvoiceLine::netFor($line->quantity_milli, $line->unit_price_minor),
                $currency,
            );

            $amount = $this->vat->fromNet($net, $line->treatment, $standardBps);

            $line->forceFill([
                'net_minor' => $amount->net->minorUnits,
                'vat_minor' => $amount->vat->minorUnits,
            ])->save();

            $subtotal = $subtotal->plus($amount->net);
            $vatTotal = $vatTotal->plus($amount->vat);
        }

        /*
         * The LBP view, which is what the VAT return declares. Converted from
         * the invoice totals rather than recomputed in lira: the invoice is
         * the tax document, so its VAT figure is the one that must be
         * declared, not a second opinion arrived at another way.
         */
        $rate = $invoice->lbp_per_usd;
        $toLbp = fn (Money $m) => $currency === Currency::LBP || ! $rate
            ? Money::lbp($m->minorUnits)
            : $m->convertTo(Currency::LBP, $rate);

        $invoice->forceFill([
            'subtotal_minor' => $subtotal->minorUnits,
            'vat_minor' => $vatTotal->minorUnits,
            'total_minor' => $subtotal->plus($vatTotal)->minorUnits,
            'net_lbp' => $toLbp($subtotal)->minorUnits,
            'vat_lbp' => $toLbp($vatTotal)->minorUnits,
            'vat_rate_bps' => $standardBps,
        ])->save();
    }
}
