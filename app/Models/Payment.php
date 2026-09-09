<?php

namespace App\Models;

use App\Support\Currency;
use App\Support\Money;
use App\Support\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received from a customer against one invoice.
 *
 * A record of something that already happened elsewhere — cash across a
 * counter, a cheque, a Whish or OMT transfer. Nothing in this product moves
 * money or talks to any payment service.
 *
 * Written only through App\Invoicing\RecordPayment, which is where the ceiling,
 * the currency check and the receipt number live.
 */
class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'receipt_number',
        'amount_minor',
        'currency',
        'lbp_per_usd',
        'method',
        'reference',
        'notes',
        'received_on',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'receipt_number' => 'integer',
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'lbp_per_usd' => 'integer',
            'method' => PaymentMethod::class,
            'received_on' => 'date',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }

    /**
     * RCT-2026-0007 — derived from the number and its year, like INV- and CN-.
     *
     * Named receiptReference() and not reference(): `reference` is already a
     * column here, holding the cheque or OMT number the CUSTOMER's payment
     * carried. Two different references, two different names.
     */
    public function receiptReference(): string
    {
        return sprintf('RCT-%d-%04d', $this->received_on->year, $this->receipt_number);
    }

    /** What this payment was worth in lira on the day it arrived, if it was in USD. */
    public function amountLbp(): ?Money
    {
        if ($this->currency === Currency::LBP) {
            return $this->amount();
        }

        return $this->lbp_per_usd
            ? $this->amount()->convertTo(Currency::LBP, (int) $this->lbp_per_usd)
            : null;
    }
}
