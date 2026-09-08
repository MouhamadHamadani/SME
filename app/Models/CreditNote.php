<?php

namespace App\Models;

use App\Invoicing\Exceptions\InvoicingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit note: the correct way to reverse an issued invoice.
 *
 * Shares the `invoices` table with Invoice, separated by `document_type` and
 * a global scope. Its own number series (CN-…), its own immutability.
 *
 * The difference that matters: a credit note reduces output VAT in the period
 * IT is issued, not the period of the invoice it corrects. That is the whole
 * reason it exists — voiding works only while a period is still open, and a
 * filed return must never be silently altered.
 */
class CreditNote extends Invoice
{
    /** Shares the invoices table; Eloquent would otherwise infer credit_notes. */
    protected $table = 'invoices';

    protected static function booted(): void
    {
        static::addGlobalScope('document_type', fn (Builder $query) => $query->where('document_type', 'credit_note'));

        static::creating(function (self $model) {
            $model->document_type = 'credit_note';
        });
    }

    /** The invoice being corrected. */
    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credited_invoice_id');
    }

    public function label(): string
    {
        return $this->reference ?? __('app.credit_notes.unnumbered');
    }

    /**
     * A credit note is never "paid" — it reduces what is owed. Marking one
     * paid would be meaningless and would corrupt the status vocabulary.
     */
    public function markPaid(): void
    {
        throw InvoicingException::creditNoteNotPayable($this->label());
    }

    /**
     * Nor is it voided.
     *
     * Voiding exists to undo an invoice while its VAT period is still open.
     * A credit note IS the mechanism for correcting after the fact; if it was
     * wrong, the answer is another document, not erasing this one.
     */
    public function void(string $reason): void
    {
        throw InvoicingException::creditNoteNotVoidable($this->label());
    }
}
