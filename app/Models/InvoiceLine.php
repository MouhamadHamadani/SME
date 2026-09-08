<?php

namespace App\Models;

use App\Support\Currency;
use App\Support\Money;
use App\Vat\VatTreatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an invoice. Amounts are in the invoice's currency.
 */
class InvoiceLine extends Model
{
    /** Quantities are stored in thousandths. */
    public const QUANTITY_SCALE = 1000;

    protected $fillable = [
        'position',
        'description',
        'quantity_milli',
        'unit_price_minor',
        'treatment',
        'net_minor',
        'vat_minor',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity_milli' => 'integer',
            'unit_price_minor' => 'integer',
            'treatment' => VatTreatment::class,
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
        ];
    }

    /**
     * The document this line belongs to — an invoice OR a credit note.
     *
     * The document_type scope is dropped deliberately: Invoice's global scope
     * would exclude credit notes, leaving a credit note's own lines with a
     * null parent and no currency to format against.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('document_type');
    }

    /**
     * quantity x unit price, in minor units, rounded half up ONCE.
     *
     * Integer throughout: 2.5 hours at 1,333 lira is 3,332.5, and which way
     * that rounds has to be decided in one place rather than by whatever
     * float happened to land.
     */
    public static function netFor(int $quantityMilli, int $unitPriceMinor): int
    {
        $product = $quantityMilli * $unitPriceMinor;
        $scale = self::QUANTITY_SCALE;

        return intdiv(2 * $product + $scale, 2 * $scale);
    }

    public function currency(): Currency
    {
        return $this->invoice->currency;
    }

    public function net(): Money
    {
        return Money::of($this->net_minor, $this->currency());
    }

    public function vat(): Money
    {
        return Money::of($this->vat_minor, $this->currency());
    }

    public function total(): Money
    {
        return $this->net()->plus($this->vat());
    }

    public function unitPrice(): Money
    {
        return Money::of($this->unit_price_minor, $this->currency());
    }

    /** Human-readable quantity: 2500 -> "2.5", 3000 -> "3". */
    public function quantity(): string
    {
        $whole = intdiv($this->quantity_milli, self::QUANTITY_SCALE);
        $fraction = $this->quantity_milli % self::QUANTITY_SCALE;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return rtrim(sprintf('%d.%03d', $whole, $fraction), '0');
    }
}
