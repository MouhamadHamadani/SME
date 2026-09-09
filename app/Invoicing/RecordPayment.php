<?php

namespace App\Invoicing;

use App\Invoicing\Exceptions\InvoicingException;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Currency;
use App\Support\Money;
use App\Support\PaymentMethod;
use App\Support\RateBasis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Record money received against an invoice.
 *
 * THE single place that decides which invoice a payment affects. Everything
 * else asks the invoice for its balance and never reaches for
 * `payment.invoice_id` itself. When one payment is eventually allowed to
 * settle several invoices, the allocation logic replaces the body of this
 * method and nothing else has to move.
 *
 * Nothing here contacts a payment provider. The money already arrived; this
 * writes down that it did.
 */
class RecordPayment
{
    public function record(
        Invoice $invoice,
        Money $amount,
        PaymentMethod $method,
        ?Carbon $receivedOn = null,
        ?string $reference = null,
        ?string $notes = null,
        ?User $by = null,
    ): Payment {
        // A credit note is not payable — it reduces what is owed. CreditNote
        // already refuses markPaid(); this is the same rule at the other door.
        if ($invoice->document_type !== 'invoice') {
            throw InvoicingException::creditNoteNotPayable($invoice->label());
        }

        // Only a real, live invoice. A draft is not owed yet and a void one is
        // not owed any more — the same shape as assertCreditable().
        if (! in_array($invoice->status, ['issued', 'paid'], true)) {
            throw InvoicingException::cannotPay($invoice->label());
        }

        /*
         * A payment in a currency the invoice is not denominated in is REFUSED,
         * never converted. Paying part of a USD invoice in lira cash is real in
         * this market, but settling it silently would mean picking an exchange
         * rate on the customer's behalf and burying it — see
         * docs/PAYMENTS-STATUS.md for the deliberate gap.
         */
        if ($amount->currency !== $invoice->currency) {
            throw InvoicingException::paymentCurrencyMismatch(
                $invoice->label(),
                $invoice->currency->value,
                $amount->currency->value,
            );
        }

        if ($amount->minorUnits <= 0) {
            throw InvoicingException::paymentNotPositive();
        }

        $receivedOn ??= Carbon::today();

        return DB::transaction(function () use ($invoice, $amount, $method, $receivedOn, $reference, $notes, $by) {
            /*
             * Number FIRST, balance second, and that order is load-bearing.
             *
             * lockForUpdate on the receipt series blocks a second concurrent
             * payment until this one commits, so the balance read below is
             * against settled state. Reading the balance first would let two
             * simultaneous payments each see the same room and together
             * overpay the invoice.
             */
            $number = $this->nextNumber();

            $remaining = $invoice->balance();

            if ($remaining->isZero()) {
                throw InvoicingException::nothingToPay($invoice->label());
            }

            if ($amount->isGreaterThan($remaining)) {
                throw InvoicingException::overPaying(
                    $invoice->label(),
                    $remaining->format().' '.$invoice->currency->value,
                );
            }

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'receipt_number' => $number,
                'amount_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                // The market rate on the day the money arrived. The books'
                // rate, not the Ministry's: a receipt is not a filing.
                'lbp_per_usd' => $amount->currency === Currency::USD
                    ? ExchangeRate::asOf($receivedOn)?->rateFor(RateBasis::Market)
                    : null,
                'method' => $method,
                'reference' => $reference ?: null,
                'notes' => $notes ?: null,
                'received_on' => $receivedOn,
                'recorded_by' => $by?->id,
            ]);

            /*
             * `status`/`paid_at` are now a CACHE of what the payments say, not
             * the record itself — this is the only code that writes them, and
             * it only does so with a Payment row already committed beside it.
             * They are kept because scopeIssued(), creditedMinor() and the
             * void guard all read `status`.
             */
            if ($invoice->fresh()->balance()->isZero()) {
                $invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            }

            return $payment;
        });
    }

    /**
     * The next receipt number, gap-free.
     *
     * The same three lines as IssueInvoice::nextNumber() against its own
     * series, deliberately rather than a shared abstraction over two callers.
     */
    private function nextNumber(): int
    {
        return ((int) Payment::query()->lockForUpdate()->max('receipt_number')) + 1;
    }
}
