<?php

namespace App\Models;

use App\Invoicing\Exceptions\InvoicingException;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
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

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * One per VAT treatment on the invoice, not one per invoice — see
     * IssueInvoice::writeVatTransactions.
     */
    public function vatTransactions(): HasMany
    {
        return $this->hasMany(VatTransaction::class);
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

    /** filed / due-soon / overdue — the shared status-badge vocabulary. */
    public function statusKey(): string
    {
        return match (true) {
            $this->isPaid() => 'filed',
            $this->isOverdue() => 'overdue',
            default => 'due-soon',
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

    public function markPaid(): void
    {
        if ($this->isDraft() || $this->isVoid()) {
            throw InvoicingException::cannotMarkPaid($this->label());
        }

        $this->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
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
