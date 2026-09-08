<?php

namespace App\Models;

use App\Support\Currency;
use App\Support\Money;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Someone on one business's payroll. Lives in that tenant's database, so there
 * is no tenant_id — the connection is the scope.
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'nssf_number',
        'base_salary_minor',
        'base_salary_currency',
        'has_dependent_spouse',
        'dependent_children',
        'is_nssf_registered',
        'started_on',
        'nssf_enrolled_on',
        'ended_on',
    ];

    protected function casts(): array
    {
        return [
            'base_salary_minor' => 'integer',
            'base_salary_currency' => Currency::class,
            'has_dependent_spouse' => 'boolean',
            'dependent_children' => 'integer',
            'is_nssf_registered' => 'boolean',
            'started_on' => 'date',
            'nssf_enrolled_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /** Bonuses and overtime. Read by the EOSI accrual, not by payroll. */
    public function payElements(): HasMany
    {
        return $this->hasMany(EmployeePayElement::class)->orderByDesc('paid_on');
    }

    public function eosiAccruals(): HasMany
    {
        return $this->hasMany(EosiAccrual::class)->orderByDesc('as_of');
    }

    public function baseSalary(): Money
    {
        return Money::of($this->base_salary_minor, $this->base_salary_currency);
    }

    /**
     * On the payroll for a given month.
     *
     * Inclusive of the month someone joins or leaves: Lebanese payroll pays
     * the part-month, so an employee who left on the 3rd still has a March
     * line. Pro-rating that line is a separate concern this session does not
     * implement — see docs/PAYROLL.md.
     */
    public function scopeActiveDuring(Builder $query, Carbon $monthStart, Carbon $monthEnd): Builder
    {
        return $query
            ->whereDate('started_on', '<=', $monthEnd)
            ->where(fn (Builder $q) => $q
                ->whereNull('ended_on')
                ->orWhereDate('ended_on', '>=', $monthStart));
    }

    /** Whole months of service at a given date — the basis for indemnity. */
    public function serviceMonthsAt(Carbon $date): int
    {
        $end = $this->ended_on && $this->ended_on->lt($date) ? $this->ended_on : $date;

        return max(0, $this->started_on->diffInMonths($end));
    }

    /**
     * Whole months of NSSF enrolment at a given date — the basis for the EOSI
     * SENIORITY COEFFICIENT, which §3.2 writes in terms of enrolment years
     * rather than service years.
     *
     * A different clock from service: someone can work for years before their
     * employer declares them, and late declaration is common enough here that
     * conflating the two would quietly overstate the coefficient. Null means
     * enrolled from the start date, which is the ordinary case.
     */
    public function enrolmentMonthsAt(Carbon $date): int
    {
        if (! $this->is_nssf_registered && ! $this->nssf_enrolled_on) {
            return 0;
        }

        $start = $this->nssf_enrolled_on ?? $this->started_on;
        $end = $this->ended_on && $this->ended_on->lt($date) ? $this->ended_on : $date;

        return max(0, $start->diffInMonths($end));
    }

    /** Total dependents, as the NSSF declaration counts them. */
    public function dependentCount(): int
    {
        return ($this->has_dependent_spouse ? 1 : 0) + $this->dependent_children;
    }
}
