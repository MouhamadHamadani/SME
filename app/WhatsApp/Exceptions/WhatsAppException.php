<?php

namespace App\WhatsApp\Exceptions;

use RuntimeException;

class WhatsAppException extends RuntimeException
{
    public static function notConnected(string $business): self
    {
        return new self(
            "{$business} has not connected a WhatsApp number. Add the Meta phone number id and "
            .'access token with `php artisan whatsapp:connect` before sending.'
        );
    }

    public static function noRecipient(string $customer): self
    {
        return new self("{$customer} has no phone number, so there is nowhere to send this.");
    }

    public static function badRecipient(string $number): self
    {
        return new self("\"{$number}\" is not a usable WhatsApp number.");
    }

    public static function notIssued(): self
    {
        return new self('Only an issued invoice can be sent. A draft has no number yet.');
    }

    public static function rejected(string $detail): self
    {
        return new self("WhatsApp rejected the message: {$detail}");
    }

    public static function unreachable(string $detail): self
    {
        return new self("Could not reach WhatsApp: {$detail}");
    }
}
