<?php

use App\Models\Customer;
use App\Models\WhatsAppMessage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'whatsapp.webhook.verify_token' => 'verify-me',
        'whatsapp.webhook.app_secret' => 'app-secret',
    ]);

    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    $this->tenant->update(['wa_phone_number_id' => 'PHONE_ID_9']);
});

/** A delivery-status callback, signed the way Meta signs it. */
function postWebhook(array $payload, ?string $secret = 'app-secret'): TestResponse
{
    $body = json_encode($payload);
    $headers = ['Content-Type' => 'application/json'];

    if ($secret !== null) {
        $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', 'http://sme.test/webhooks/whatsapp', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => $headers['X-Hub-Signature-256'] ?? null,
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function statusPayload(string $messageId, string $status, string $phoneNumberId = 'PHONE_ID_9'): array
{
    return ['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $phoneNumberId],
        'statuses' => [[
            'id' => $messageId,
            'status' => $status,
            'timestamp' => (string) now()->timestamp,
        ]],
    ]]]]]];
}

/** A sent message in the tenant's log, awaiting a delivery callback. */
function sentMessage(string $providerId = 'wamid.ABC'): void
{
    test()->tenant->run(function () use ($providerId) {
        WhatsAppMessage::create([
            'customer_id' => Customer::factory()->create()->id,
            'recipient' => '9613123456',
            'template' => 'invoice_notification',
            'language' => 'ar',
            'status' => 'sent',
            'provider_message_id' => $providerId,
            'sent_at' => now(),
        ]);
    });
}

// -- Verification handshake -------------------------------------------------

it('echoes the challenge when the verify token matches', function () {
    $this->get('http://sme.test/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')
        ->assertOk()
        ->assertSee('12345');
});

it('refuses a wrong verify token', function () {
    $this->get('http://sme.test/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
        ->assertForbidden();
});

it('refuses verification entirely when unconfigured', function () {
    // An unconfigured install must not accept whatever token a caller offers.
    config(['whatsapp.webhook.verify_token' => null]);

    $this->get('http://sme.test/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=anything&hub_challenge=1')
        ->assertStatus(503);
});

// -- Signature --------------------------------------------------------------

it('rejects a callback with no signature', function () {
    sentMessage();

    postWebhook(statusPayload('wamid.ABC', 'delivered'), secret: null)->assertForbidden();

    $this->tenant->run(fn () => expect(WhatsAppMessage::sole()->status)->toBe('sent'));
});

it('rejects a callback signed with the wrong secret', function () {
    // Without this, anyone could post fake "delivered" statuses and a business
    // would believe an invoice arrived when it never did.
    sentMessage();

    postWebhook(statusPayload('wamid.ABC', 'delivered'), secret: 'not-the-secret')->assertForbidden();

    $this->tenant->run(fn () => expect(WhatsAppMessage::sole()->status)->toBe('sent'));
});

it('rejects everything when no app secret is configured', function () {
    config(['whatsapp.webhook.app_secret' => null]);
    sentMessage();

    postWebhook(statusPayload('wamid.ABC', 'delivered'))->assertForbidden();
});

// -- Status updates ---------------------------------------------------------

it('marks a message delivered', function () {
    sentMessage();

    postWebhook(statusPayload('wamid.ABC', 'delivered'))->assertOk();

    $this->tenant->run(function () {
        $message = WhatsAppMessage::sole();
        expect($message->status)->toBe('delivered')
            ->and($message->delivered_at)->not->toBeNull()
            ->and($message->isDelivered())->toBeTrue();
    });
});

it('marks a message read, backfilling delivered when Meta skips it', function () {
    sentMessage();

    postWebhook(statusPayload('wamid.ABC', 'read'))->assertOk();

    $this->tenant->run(function () {
        $message = WhatsAppMessage::sole();
        // Read implies delivered; the delivered callback is not guaranteed.
        expect($message->status)->toBe('read')
            ->and($message->read_at)->not->toBeNull()
            ->and($message->delivered_at)->not->toBeNull();
    });
});

it('records a delivery failure with Meta\'s reason', function () {
    sentMessage();

    $payload = statusPayload('wamid.ABC', 'failed');
    $payload['entry'][0]['changes'][0]['value']['statuses'][0]['errors'] = [
        ['title' => 'Message undeliverable'],
    ];

    postWebhook($payload)->assertOk();

    $this->tenant->run(function () {
        expect(WhatsAppMessage::sole()->status)->toBe('failed')
            ->and(WhatsAppMessage::sole()->error)->toBe('Message undeliverable');
    });
});

it('routes the callback to the right business', function () {
    $globex = makeTenant('globex');
    $globex->update(['wa_phone_number_id' => 'PHONE_ID_OTHER']);

    sentMessage();
    $globex->run(function () {
        WhatsAppMessage::create([
            'recipient' => '9613999999', 'template' => 't', 'language' => 'ar',
            'status' => 'sent', 'provider_message_id' => 'wamid.ABC', 'sent_at' => now(),
        ]);
    });

    // Same provider id in both businesses; only the addressed one moves.
    postWebhook(statusPayload('wamid.ABC', 'delivered', 'PHONE_ID_9'))->assertOk();

    $this->tenant->run(fn () => expect(WhatsAppMessage::sole()->status)->toBe('delivered'));
    $globex->run(fn () => expect(WhatsAppMessage::sole()->status)->toBe('sent'));
});

it('acknowledges a callback for a number we do not host', function () {
    // Meta retries anything non-2xx, and retrying will not make an unknown
    // number known.
    postWebhook(statusPayload('wamid.ABC', 'delivered', 'PHONE_ID_UNKNOWN'))->assertOk();
});

it('acknowledges a payload it cannot interpret', function () {
    postWebhook(['entry' => [['changes' => [['value' => []]]]]])->assertOk();
    postWebhook([])->assertOk();
});

it('ignores a status for a message it does not know', function () {
    sentMessage('wamid.KNOWN');

    postWebhook(statusPayload('wamid.SOMETHING-ELSE', 'delivered'))->assertOk();

    $this->tenant->run(fn () => expect(WhatsAppMessage::sole()->status)->toBe('sent'));
});
