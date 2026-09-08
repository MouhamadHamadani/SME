<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * One customer business. Each gets its own database — see the migration for why.
 *
 * Field set follows the house pattern used across the other SaaS repos in this
 * workspace, so tenancy looks the same wherever you land:
 *   id (uuid) · name · domain (subdomain) · plan · wa_phone_number · settings
 *
 * `plan` maps 1:1 onto the published pricing tiers (knowledge base §7) that the
 * marketing site advertises — starter $19 / growth $49 / pro $99. Keep the keys
 * in step with the `pricing.tiers` keys in the marketing lang files.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    public const PLANS = ['starter', 'growth', 'pro'];

    /**
     * stancl stores anything not listed here inside the `data` JSON column.
     * Promoting these to real columns keeps them queryable from the central
     * database (e.g. "every tenant on the growth plan").
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'plan',
            'wa_phone_number',
        ];
    }

    /**
     * Per-tenant configuration. `exchange_rate` is the LBP/USD rate this
     * business books at; `language` is their default UI locale.
     *
     * The rate lives here rather than in a global config because it is a
     * business decision, not a system constant — two tenants can legitimately
     * book at different rates on the same day.
     */
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value) {
                $settings = array_merge([
                    'exchange_rate' => null,
                    'currency' => 'USD',
                    'language' => 'ar',
                ], is_string($value) ? (json_decode($value, true) ?? []) : ($value ?? []));

                // json_encode(89500.0) round-trips to int. Always hand back a
                // float so money maths never depends on how it was stored.
                $settings['exchange_rate'] = $settings['exchange_rate'] === null
                    ? null
                    : (float) $settings['exchange_rate'];

                return $settings;
            },
            set: fn (mixed $value) => is_string($value) ? $value : json_encode($value),
        );
    }

    public function isOnPlan(string $plan): bool
    {
        return $this->plan === $plan;
    }
}
