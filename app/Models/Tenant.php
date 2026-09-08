<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;
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
            'wa_phone_number_id',
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

    /**
     * Meta Cloud API credentials for this business, or null if not connected.
     *
     * The access token is stored ENCRYPTED in the tenant's settings: it is a
     * bearer credential that can send messages as the business, and the
     * central tenants table is not a place to keep one in clear text.
     *
     * @return array{phone_number_id: string, access_token: string}|null
     */
    public function whatsappCredentials(): ?array
    {
        $token = $this->settings['whatsapp_access_token'] ?? null;

        if (! $this->wa_phone_number_id || ! $token) {
            return null;
        }

        try {
            $token = Crypt::decryptString($token);
        } catch (DecryptException) {
            // A token encrypted under a rotated APP_KEY is unusable. Treat it
            // as not connected rather than sending garbage as a bearer token.
            return null;
        }

        return [
            'phone_number_id' => (string) $this->wa_phone_number_id,
            'access_token' => $token,
        ];
    }

    public function connectWhatsApp(string $phoneNumberId, string $accessToken, ?string $displayNumber = null): void
    {
        $this->update([
            'wa_phone_number_id' => $phoneNumberId,
            'wa_phone_number' => $displayNumber ?? $this->wa_phone_number,
            'settings' => array_merge($this->settings, [
                'whatsapp_access_token' => Crypt::encryptString($accessToken),
                'whatsapp_connected_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Forget the credentials.
     *
     * The token is REMOVED, not blanked: a business that disconnects has
     * usually revoked or rotated it, and keeping a dead bearer credential in
     * the row serves nobody. The delivery log stays — "did the customer get
     * it?" still has to be answerable afterwards.
     */
    public function disconnectWhatsApp(): void
    {
        $settings = $this->settings;
        unset($settings['whatsapp_access_token'], $settings['whatsapp_connected_at']);

        $this->update([
            'wa_phone_number_id' => null,
            'wa_phone_number' => null,
            'settings' => $settings,
        ]);
    }

    public function hasWhatsApp(): bool
    {
        return $this->whatsappCredentials() !== null;
    }

    public function whatsappConnectedAt(): ?string
    {
        return $this->settings['whatsapp_connected_at'] ?? null;
    }
}
