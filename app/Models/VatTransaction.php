<?php

namespace App\Models;

use App\Support\Currency;
use App\Support\Money;
use App\Vat\VatTreatment;
use Database\Factories\VatTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sale or purchase, for VAT purposes.
 */
class VatTransaction extends Model
{
    /** @use HasFactory<VatTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'direction',
        'treatment',
        'transacted_on',
        'description',
        'counterparty',
        'counterparty_vat_number',
        'amount_minor',
        'amount_currency',
        'lbp_per_usd',
        'net_lbp',
        'vat_lbp',
        'is_recoverable',
        'vat_return_id',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'treatment' => VatTreatment::class,
            'transacted_on' => 'date',
            'amount_minor' => 'integer',
            'amount_currency' => Currency::class,
            'lbp_per_usd' => 'integer',
            'net_lbp' => 'integer',
            'vat_lbp' => 'integer',
            'is_recoverable' => 'boolean',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeSales(Builder $query): Builder
    {
        return $query->where('direction', 'sale');
    }

    public function scopePurchases(Builder $query): Builder
    {
        return $query->where('direction', 'purchase');
    }

    /**
     * Transactions dated within a period, INCLUSIVE of both end days.
     *
     * Deliberately a datetime range rather than `whereBetween` on date
     * strings. The column stores "2026-03-31 00:00:00", and string-comparing
     * that against "2026-03-31" puts it *after* the upper bound — which
     * silently dropped every sale made on the last day of the quarter from
     * the return. Bounding by start-of-day and end-of-day is correct whether
     * the column holds a bare date or a datetime, and still uses the index.
     */
    public function scopeInPeriod(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query
            ->where('transacted_on', '>=', $start->copy()->startOfDay())
            ->where('transacted_on', '<=', $end->copy()->endOfDay());
    }

    /** Already claimed on a finalised return, so it must not be counted twice. */
    public function isLocked(): bool
    {
        return $this->vat_return_id !== null;
    }

    public function net(): Money
    {
        return Money::lbp($this->net_lbp);
    }

    public function vat(): Money
    {
        return Money::lbp($this->vat_lbp);
    }

    public function gross(): Money
    {
        return $this->net()->plus($this->vat());
    }
}
