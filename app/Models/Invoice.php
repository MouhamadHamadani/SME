<?php

namespace App\Models;

use App\Invoicing\Exceptions\InvoicingException;
use App\Invoicing\RecordPayment;
use App\Support\Currency;
use App\Support\Money;
use App\Support\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /**
     * Invoices and credit notes share this table, separated by
     * `document_type`. A global scope pins each model to its own type, so no
     * existing query has to remember the filter — forgetting one would show a
     * credit note as an invoice or double-count it, which is exactly the
     * failure this arrangement rules out.
     *
     * CreditNote overrides `booted()` to scope itself the other way.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('document_type', fn (Builder $query) => $query->where('document_type', 'invoice'));

        static::creating(function (self $model) {
            $model->document_type ??= 'invoice';
        });
    }

    protected $fillable = [
        'document_type',
        'credited_invoice_id',
        'number',
        'reference',
        'customer_id',
        'status',
        'issued_on',
        'due_on',
        'paid_at',
        'void_reason',
        'currency',
        'lbp_per_usd',
        'mof_rate_lbp_per_usd',
        'subtotal_minor',
        'vat_minor',
        'total_minor',
        'net_lbp',
        'vat_lbp',
        'vat_rate_bps',
        'vat_rate_schedule_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'issued_on' => 'date',
            'due_on' => 'date',
            'paid_at' => 'datetime',
            'currency' => Currency::class,
            'lbp_per_usd' => 'integer',
            'mof_rate_lbp_per_usd' => 'integer',
            'subtotal_minor' => 'integer',
            'vat_minor' => 'integer',
            'total_minor' => 'integer',
            'net_lbp' => 'integer',
            'vat_lbp' => 'integer',
            'vat_rate_bps' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /*
     | Foreign keys are named explicitly on both relations below. CreditNote
     | extends this class, and Eloquent would otherwise infer the key from the
     | child's name and look for `credit_note_id` — both documents live in the
     | invoices table and are referenced by `invoice_id`.
     */

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id')->orderBy('position');
    }

    /**
     * One per VAT treatment on the document — see
     * IssueInvoice::writeVatTransactions.
     */
    public function vatTransactions(): HasMany
    {
        return $this->hasMany(VatTransaction::class, 'invoice_id');
    }

    /** Credit notes raised against this invoice. */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'credited_invoice_id');
    }

    /**
     * How much of this invoice has already been credited, in its own currency.
     *
     * Only ISSUED credit notes count. A draft can still change, and crediting
     * against a moving figure would let two drafts each look affordable while
     * together they over-credit the invoice.
     */
    public function creditedMinor(): int
    {
        return (int) $this->creditNotes()
            ->whereIn('status', ['issued', 'paid'])
            ->sum('total_minor');
    }

    /** What is left to credit. Never negative. */
    public function creditableMinor(): int
    {
        return max(0, $this->total_minor - $this->creditedMinor());
    }

    /** Money received against this invoice. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id')->orderBy('received_on')->orderBy('id');
    }

    /**
     * What has actually been received, in the invoice's own currency.
     *
     * Every payment is in that currency — RecordPayment refuses any other —
     * so this is a plain sum rather than a conversion.
     */
    public function amountPaid(): Money
    {
        return Money::of((int) $this->payments()->sum('amount_minor'), $this->currency);
    }

    /**
     * What is still owed: the total, less credit notes, less payments.
     *
     * Both effects in ONE number. An invoice that has been partly credited and
     * partly paid owes neither the uncredited part nor the unpaid part but the
     * overlap of the two, and reporting either half alone is simply a wrong
     * figure. Never negative, matching creditableMinor().
     */
    public function balance(): Money
    {
        return Money::of(
            max(0, $this->total_minor - $this->creditedMinor() - $this->amountPaid()->minorUnits),
            $this->currency,
        );
    }

    /**
     * Settled by money, not by credit.
     *
     * A fully CREDITED invoice also has a zero balance and is deliberately not
     * "paid" — nobody paid it. That distinction is why this asks for a payment
     * to exist rather than only checking the balance.
     */
    public function isFullyPaid(): bool
    {
        return $this->amountPaid()->minorUnits > 0 && $this->balance()->isZero();
    }

    public function isPartiallyPaid(): bool
    {
        return $this->amountPaid()->minorUnits > 0 && ! $this->balance()->isZero();
    }

    public function isFullyCredited(): bool
    {
        return $this->status !== 'draft' && $this->creditableMinor() === 0 && $this->total_minor > 0;
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereIn('status', ['issued', 'paid']);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Issued invoices are the tax record and cannot be edited.
     *
     * Correcting one means voiding it or raising a credit note, never quietly
     * changing the figures somebody has already been sent and may have
     * reclaimed VAT against.
     */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function isOverdue(): bool
    {
        return $this->status === 'issued'
            && $this->due_on !== null
            && $this->due_on->isPast();
    }

    /**
     * filed / due-soon / overdue — the shared status-badge vocabulary.
     *
     * OVERDUE AND PARTIALLY PAID resolves to `overdue`, deliberately. A part
     * payment does not cure lateness: money is still late, and an amber badge
     * on an invoice a customer is behind on is the wrong signal to give the
     * person chasing it. The partial settlement is not lost — it is said in
     * words by statusLabel() and in figures by the balance beside it. Green is
     * reserved for a balance of zero.
     */
    public function statusKey(): string
    {
        return match (true) {
            $this->isPaid() => 'filed',
            $this->isOverdue() => 'overdue',
            default => 'due-soon',
        };
    }

    /**
     * What the badge SAYS, as opposed to what colour it is.
     *
     * Four states over three colours, which is why the label is its own method
     * rather than a ternary repeated in every view that renders the badge.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isPaid() => __('app.invoices.status_paid'),
            $this->isOverdue() && $this->isPartiallyPaid() => __('app.invoices.status_overdue_part_paid'),
            $this->isOverdue() => __('app.invoices.status_overdue'),
            $this->isPartiallyPaid() => __('app.invoices.status_part_paid'),
            default => __('app.invoices.status_issued'),
        };
    }

    public function subtotal(): Money
    {
        return Money::of($this->subtotal_minor, $this->currency);
    }

    public function vat(): Money
    {
        return Money::of($this->vat_minor, $this->currency);
    }

    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }

    /** What the VAT return will declare — always LBP. */
    public function vatLbp(): Money
    {
        return Money::lbp($this->vat_lbp);
    }

    public function label(): string
    {
        return $this->reference ?? __('app.invoices.unnumbered');
    }

    /**
     * Settle the whole outstanding balance in one payment.
     *
     * A thin wrapper over RecordPayment, kept because "this invoice is now
     * paid in full" is a sentence worth having — but it goes through the same
     * door as everything else, so there is no path that marks an invoice paid
     * without a real Payment row behind it. The UI does not call this: the
     * record-payment form does, with a method and a date the person chose.
     * Hence the deliberately vague default method here.
     */
    public function markPaid(PaymentMethod $method = PaymentMethod::Other, ?User $by = null): Payment
    {
        if ($this->isDraft() || $this->isVoid()) {
            throw InvoicingException::cannotMarkPaid($this->label());
        }

        return app(RecordPayment::class)->record($this, $this->balance(), $method, by: $by);
    }

    /**
     * Void an issued invoice.
     *
     * The row is kept and the number is NOT released — a tax invoice series
     * must stay gap-free, and a reused number is worse than a voided one.
     * The VAT transaction it wrote is removed so the return stops counting it.
     */
    public function void(string $reason): void
    {
        if ($this->isDraft()) {
            throw InvoicingException::cannotVoidDraft();
        }

        if ($this->isVoid()) {
            throw InvoicingException::alreadyVoid($this->label());
        }

        /*
         * A paid or part-paid invoice is a settled transaction. Voiding it
         * would erase the payment history along with the VAT it declared, and
         * leave receipts pointing at a document that says it never happened.
         * Correct it with a credit note instead — the same answer this module
         * already gives once a VAT period is filed.
         */
        if ($this->payments()->exists()) {
            throw InvoicingException::cannotVoidPaid($this->label());
        }

        $transactions = $this->vatTransactions()->get();

        // If ANY of them is already claimed on a filed return, removing them
        // would alter a period that has been declared.
        if ($transactions->contains(fn (VatTransaction $t) => $t->isLocked())) {
            throw InvoicingException::vatAlreadyFiled($this->label());
        }

        $this->vatTransactions()->delete();

        $this->forceFill([
            'status' => 'void',
            'void_reason' => $reason,
        ])->save();
    }
}
