<?php

namespace App\WhatsApp;

/** What a sender returns when Meta has accepted a message. */
final readonly class SentMessage
{
    public function __construct(
        public string $providerMessageId,
        /** @var array<string, mixed> */
        public array $payload,
    ) {}
}
