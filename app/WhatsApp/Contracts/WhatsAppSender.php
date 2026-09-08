<?php

namespace App\WhatsApp\Contracts;

use App\WhatsApp\Exceptions\WhatsAppException;
use App\WhatsApp\SentMessage;
use App\WhatsApp\TemplateMessage;

interface WhatsAppSender
{
    /**
     * Hand a template message to the provider.
     *
     * @throws WhatsAppException when it cannot be sent
     */
    public function send(TemplateMessage $message): SentMessage;
}
