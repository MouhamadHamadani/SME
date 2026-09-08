<?php

namespace App\WhatsApp;

use App\Models\Tenant;
use App\WhatsApp\Contracts\WhatsAppSender;
use App\WhatsApp\Exceptions\WhatsAppException;
use App\WhatsApp\Senders\CloudApiSender;
use App\WhatsApp\Senders\LogSender;

/**
 * Builds the sender for the business currently in context.
 *
 * Credentials are per tenant: each business connects its own Meta number, so
 * one business's invoices can never go out from another's. The access token is
 * stored encrypted — see Tenant::whatsappCredentials().
 */
class SenderFactory
{
    public function for(Tenant $tenant): WhatsAppSender
    {
        if (config('whatsapp.driver') !== 'cloud') {
            // Anything other than an explicit "cloud" is treated as not-live.
            return new LogSender;
        }

        $credentials = $tenant->whatsappCredentials();

        if ($credentials === null) {
            throw WhatsAppException::notConnected($tenant->name);
        }

        return new CloudApiSender($credentials['phone_number_id'], $credentials['access_token']);
    }
}
