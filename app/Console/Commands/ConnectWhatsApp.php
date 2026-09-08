<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Connect a business's Meta WhatsApp number.
 *
 *   php artisan whatsapp:connect arzclinic --phone-number-id=1234567890
 *
 * The access token is prompted for rather than passed as an argument: it is a
 * bearer credential and arguments end up in shell history and process lists.
 */
class ConnectWhatsApp extends Command
{
    protected $signature = 'whatsapp:connect
        {tenant : Business subdomain}
        {--phone-number-id= : Meta phone number id}
        {--display= : The number as customers see it, e.g. +96170123456}
        {--show : Show what is currently connected instead of changing it}';

    protected $description = 'Connect (or inspect) a business WhatsApp number';

    public function handle(): int
    {
        $tenant = Domain::where('domain', $this->argument('tenant'))->first()?->tenant;

        if (! $tenant instanceof Tenant) {
            $this->components->error('No business with that subdomain.');

            return self::FAILURE;
        }

        if ($this->option('show')) {
            return $this->show($tenant);
        }

        $phoneNumberId = $this->option('phone-number-id') ?: $this->ask('Meta phone number id');

        if (! $phoneNumberId) {
            $this->components->error('A phone number id is required.');

            return self::FAILURE;
        }

        // secret(): not echoed, and not left in shell history.
        $token = $this->secret('Meta access token (input hidden)');

        if (! $token) {
            $this->components->error('An access token is required.');

            return self::FAILURE;
        }

        $tenant->connectWhatsApp($phoneNumberId, $token, $this->option('display'));

        $this->components->info("Connected WhatsApp for {$tenant->name}.");
        $this->components->twoColumnDetail('Phone number id', $phoneNumberId);
        $this->components->twoColumnDetail('Token', 'stored encrypted');

        if (config('whatsapp.driver') !== 'cloud') {
            $this->newLine();
            $this->components->warn(
                'WHATSAPP_DRIVER is "'.config('whatsapp.driver').'", so nothing is actually sent. '
                .'Set WHATSAPP_DRIVER=cloud once the number and templates are approved.'
            );
        }

        return self::SUCCESS;
    }

    private function show(Tenant $tenant): int
    {
        $this->components->twoColumnDetail('Business', $tenant->name);
        $this->components->twoColumnDetail('Driver', (string) config('whatsapp.driver'));
        $this->components->twoColumnDetail('Phone number id', $tenant->wa_phone_number_id ?: 'not set');
        $this->components->twoColumnDetail('Display number', $tenant->wa_phone_number ?: 'not set');
        $this->components->twoColumnDetail(
            'Access token',
            $tenant->hasWhatsApp() ? 'stored and decryptable' : 'missing or unreadable'
        );

        return self::SUCCESS;
    }
}
