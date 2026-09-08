<?php

namespace App\Models;

use App\Support\Money;
use App\Vat\Exceptions\VatException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One quarterly VAT return.
 */
class VatReturn extends Model
{
    protected $fillable = [
        'period_year',
        'period_quarter',
        'vat_rate_schedule_id',
        'standard_rate_bps',
        'filing_deadline_days',
        'due_on',
        'taxable_sales_lbp',
        'exempt_sales_lbp',
        'output_vat_lbp',
        'purchases_lbp',
        'input_vat_lbp',
        'credit_brought_forward_lbp',
        'net_position_lbp',
        'payable_lbp',
        'credit_carried_forward_lbp',
        'status',
        'finalised_at',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_quarter' => 'integer',
            'standard_rate_bps' => 'integer',
            'filing_deadline_days' => 'integer',
            'due_on' => 'date',
            'taxable_sales_lbp' => 'integer',
            'exempt_sales_lbp' => 'integer',
            'output_vat_lbp' => 'integer',
            'purchases_lbp' => 'integer',
            'input_vat_lbp' => 'integer',
            'credit_brought_forward_lbp' => 'integer',
            'net_position_lbp' => 'integer',
            'payable_lbp' => 'integer',
            'credit_carried_forward_lbp' => 'integer',
            'finalised_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(VatTransaction::class);
    }

    public static function periodStart(int $year, int $quarter): Carbon
    {
        return Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfMonth();
    }

    public static function periodEnd(int $year, int $quarter): Carbon
    {
        return self::periodStart($year, $quarter)->addMonthsNoOverflow(2)->endOfMonth();
    }

    public function start(): Carbon
    {
        return self::periodStart($this->period_year, $this->period_quarter);
    }

    public function end(): Carbon
    {
        return self::periodEnd($this->period_year, $this->period_quarter);
    }

    public function periodLabel(): string
    {
        return sprintf('%04d-Q%d', $this->period_year, $this->period_quarter);
    }

    public function isFinalised(): bool
    {
        return $this->status === 'finalised';
    }

    public function isOverdue(): bool
    {
        return ! $this->isFinalised() && $this->due_on->isPast();
    }

    /** Days until the filing deadline; negative once it has passed. */
    public function daysUntilDue(): int
    {
        return (int) Carbon::today()->diffInDays($this->due_on, false);
    }

    /** filed / due-soon / overdue — the same keys the status badge uses. */
    public function statusKey(): string
    {
        if ($this->isFinalised()) {
            return 'filed';
        }

        return $this->isOverdue() ? 'overdue' : 'due-soon';
    }

    public function outputVat(): Money
    {
        return Money::lbp($this->output_vat_lbp);
    }

    public function inputVat(): Money
    {
        return Money::lbp($this->input_vat_lbp);
    }

    public function payable(): Money
    {
        return Money::lbp($this->payable_lbp);
    }

    public function creditCarriedForward(): Money
    {
        return Money::lbp($this->credit_carried_forward_lbp);
    }

    public function isInCredit(): bool
    {
        return $this->credit_carried_forward_lbp > 0;
    }

    /**
     * Lock the return and stamp its transactions so they cannot be claimed
     * again on a later period.
     *
     * Refuses on unverified rates, for the same reason payroll does: a draft
     * built on provisional figures is useful, a filed one is a liability.
     */
    public function finalise(): void
    {
        if ($this->isFinalised()) {
            throw VatException::alreadyFinalised($this->periodLabel());
        }

        $schedule = $this->vat_rate_schedule_id
            ? VatRateSchedule::find($this->vat_rate_schedule_id)
            : null;

        if (! $schedule?->isVerified()) {
            throw VatException::ratesNotVerified($this->periodLabel());
        }

        VatTransaction::inPeriod($this->start(), $this->end())
            ->whereNull('vat_return_id')
            ->update(['vat_return_id' => $this->id]);

        $this->forceFill(['status' => 'finalised', 'finalised_at' => now()])->save();
    }
}
