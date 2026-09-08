<?php

namespace App\Models;

use App\Payroll\NssfRates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * NSSF rates in force for a period. Central, shared by every business.
 *
 * @property-read NssfRates $rates
 */
class NssfRateSchedule extends Model
{
    protected $fillable = [
        'effective_from',
        'effective_to',
        'sickness_employer_bps',
        'sickness_employee_bps',
        'sickness_ceiling_lbp',
        'family_employer_bps',
        'family_ceiling_lbp',
        'eosi_employer_bps',
        'source_note',
        'verified_at',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'verified_at' => 'datetime',
            'sickness_employer_bps' => 'integer',
            'sickness_employee_bps' => 'integer',
            'sickness_ceiling_lbp' => 'integer',
            'family_employer_bps' => 'integer',
            'family_ceiling_lbp' => 'integer',
            'eosi_employer_bps' => 'integer',
        ];
    }

    /**
     * Pinned to the central connection.
     *
     * Tenancy repoints `database.default` at the tenant, and these rows live
     * centrally. Without this, a lookup during a payroll run would go looking
     * for `nssf_rate_schedules` inside the business's own database. Same rule
     * as sessions, cache and queue — see docs/ARCHITECTURE.md.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection', config('database.default'));
    }

    /** The schedule in force on a given date, or null if there is a gap. */
    public static function inForceOn(Carbon $date): ?self
    {
        return static::query()
            ->where('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function getRatesAttribute(): NssfRates
    {
        return NssfRates::fromArray($this->attributesToArray());
    }
}
