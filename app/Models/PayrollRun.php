<?php

namespace App\Models;

use App\Payroll\Exceptions\PayrollException;
use App\Payroll\Money;
use App\Payroll\NssfRates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One month of payroll for one business.
 *
 * @property-read NssfRates $rates
 */
class PayrollRun extends Model
{
    protected $fillable = [
        'period_year',
        'period_month',
        'lbp_per_usd',
        'nssf_rate_schedule_id',
        'sickness_employer_bps',
        'sickness_employee_bps',
        'sickness_ceiling_lbp',
        'family_employer_bps',
        'family_ceiling_lbp',
        'eosi_employer_bps',
        'status',
        'finalised_at',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'lbp_per_usd' => 'integer',
            'sickness_employer_bps' => 'integer',
            'sickness_employee_bps' => 'integer',
            'sickness_ceiling_lbp' => 'integer',
            'family_employer_bps' => 'integer',
            'family_ceiling_lbp' => 'integer',
            'eosi_employer_bps' => 'integer',
            'finalised_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /** The rules this run was computed with — snapshotted, not looked up. */
    public function getRatesAttribute(): NssfRates
    {
        return NssfRates::fromArray($this->attributesToArray());
    }

    public function periodStart(): Carbon
    {
        return Carbon::create($this->period_year, $this->period_month, 1)->startOfMonth();
    }

    public function periodEnd(): Carbon
    {
        return $this->periodStart()->endOfMonth();
    }

    public function periodLabel(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }

    public function isFinalised(): bool
    {
        return $this->status === 'finalised';
    }

    /**
     * Lock the run.
     *
     * Refuses when the rates were never verified against an official decree.
     * A draft computed from provisional figures is useful; a finalised
     * declaration built on them is a liability, and the whole product promise
     * is that the numbers are current and right.
     */
    public function finalise(): void
    {
        if ($this->isFinalised()) {
            throw PayrollException::alreadyFinalised($this->periodLabel());
        }

        $schedule = $this->nssf_rate_schedule_id
            ? NssfRateSchedule::find($this->nssf_rate_schedule_id)
            : null;

        if (! $schedule?->isVerified()) {
            throw PayrollException::ratesNotVerified($this->periodLabel());
        }

        $this->forceFill([
            'status' => 'finalised',
            'finalised_at' => now(),
        ])->save();
    }

    // -- Totals, for the declaration ---------------------------------------

    public function totalGross(): Money
    {
        return Money::lbp((int) $this->lines()->sum('gross_lbp'));
    }

    public function totalEmployeeDeductions(): Money
    {
        return Money::lbp((int) $this->lines()->sum('sickness_employee_lbp'));
    }

    public function totalEmployerContributions(): Money
    {
        return Money::lbp((int) $this->lines()->sum('sickness_employer_lbp'))
            ->plus(Money::lbp((int) $this->lines()->sum('family_employer_lbp')))
            ->plus(Money::lbp((int) $this->lines()->sum('eosi_employer_lbp')));
    }

    public function totalEmployerCost(): Money
    {
        return $this->totalGross()->plus($this->totalEmployerContributions());
    }

    public function totalNet(): Money
    {
        return $this->totalGross()->minus($this->totalEmployeeDeductions());
    }
}
