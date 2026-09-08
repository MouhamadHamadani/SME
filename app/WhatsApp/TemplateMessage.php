<?php

namespace App\WhatsApp;

use App\Support\PhoneNumber;

/**
 * A business-initiated WhatsApp message.
 *
 * Deliberately template-only. Meta allows free-form text ONLY inside the
 * 24-hour window after a customer messages you; an invoice notification is
 * business-initiated and outside it by definition, so free text would be
 * rejected by the API. Modelling only what is actually sendable stops that
 * being discovered in production.
 *
 * The template itself is written and approved in Meta's Business Manager.
 * `variables` fill its {{1}}, {{2}}… placeholders in order.
 */
final readonly class TemplateMessage
{
    /**
     * @param  array<int, string>  $variables
     */
    public function __construct(
        public PhoneNumber $to,
        public string $template,
        public string $language,
        public array $variables = [],
    ) {}

    /**
     * The Cloud API request body.
     *
     * Kept here rather than in the sender so the exact payload can be asserted
     * in a test and stored on the delivery log without a live call.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            // E.164 digits, no leading plus.
            'to' => $this->to->e164,
            'type' => 'template',
            'template' => [
                'name' => $this->template,
                'language' => ['code' => $this->language],
            ],
        ];

        if ($this->variables !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value) => ['type' => 'text', 'text' => $value],
                    array_values($this->variables),
                ),
            ]];
        }

        return $payload;
    }
}
