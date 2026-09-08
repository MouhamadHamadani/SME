<?php

namespace App\WhatsApp\Senders;

use App\WhatsApp\Contracts\WhatsAppSender;
use App\WhatsApp\SentMessage;
use App\WhatsApp\TemplateMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes what it would have sent and returns a plausible id.
 *
 * The DEFAULT driver, on purpose. Every developer machine and every fresh
 * install is unconfigured, and the failure mode of a real driver by default is
 * messaging an actual customer during a test. Nothing leaves the machine here.
 */
class LogSender implements WhatsAppSender
{
    public function send(TemplateMessage $message): SentMessage
    {
        $payload = $message->toPayload();

        Log::info('WhatsApp message (log driver — nothing was sent)', [
            'to' => $message->to->forDisplay(),
            'template' => $message->template,
            'language' => $message->language,
            'variables' => $message->variables,
        ]);

        // Shaped like Meta's wamid so nothing downstream learns to expect a
        // different format from the fake than from the real thing.
        return new SentMessage('wamid.LOG-'.Str::upper(Str::random(24)), $payload);
    }
}
