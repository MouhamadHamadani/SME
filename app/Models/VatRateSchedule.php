<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * VAT rate and filing rules in force for a period. Central, shared by everyone.
 */
class VatRateSchedule extends Model
{
    protected $fillable = [
        'effective_from',
        'effective_to',
        'standard_rate_bps',
        'registration_threshold_lbp',
        'filing_deadline_days',
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
            'standard_rate_bps' => 'integer',
            'registration_threshold_lbp' => 'integer',
            'filing_deadline_days' => 'integer',
        ];
    }

    /**
     * Pinned to the central connection — tenancy repoints `database.default`,
     * and these rows are national law held once. Same rule as
     * NssfRateSchedule; see docs/ARCHITECTURE.md.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection', config('database.default'));
    }

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
}
