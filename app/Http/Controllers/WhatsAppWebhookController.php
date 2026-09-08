<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Delivery callbacks from Meta.
 *
 * CENTRAL and unauthenticated: Meta calls one url for the whole app and has no
 * session. It identifies the business only by `phone_number_id` in the
 * payload, so this looks the tenant up first and then enters tenant context to
 * touch that business's own delivery log.
 *
 * Nothing here trusts the request until the signature checks out.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Meta's one-time subscription handshake: echo the challenge back if the
     * verify token matches.
     */
    public function verify(Request $request): Response
    {
        $expected = config('whatsapp.webhook.verify_token');

        if (! $expected) {
            // Unconfigured: refuse rather than accepting any caller's token.
            return response('Webhook not configured', 503);
        }

        $matches = $request->query('hub_mode') === 'subscribe'
            && is_string($request->query('hub_verify_token'))
            && hash_equals($expected, (string) $request->query('hub_verify_token'));

        if (! $matches) {
            return response('Verification failed', 403);
        }

        return response((string) $request->query('hub_challenge'), 200);
    }

    /**
     * Status updates: sent -> delivered -> read, or failed.
     */
    public function handle(Request $request): Response
    {
        if (! $this->signatureIsValid($request)) {
            // Without this, anyone could post fake "delivered" statuses and
            // a business would believe an invoice arrived when it never did.
            return response('Invalid signature', 403);
        }

        foreach ($this->statuses($request) as [$phoneNumberId, $status]) {
            $tenant = Tenant::where('wa_phone_number_id', $phoneNumberId)->first();

            if (! $tenant) {
                // A callback for a number we do not host. Acknowledge it —
                // Meta retries anything non-2xx, and retrying will not help.
                Log::warning('WhatsApp webhook for an unknown phone_number_id', ['id' => $phoneNumberId]);

                continue;
            }

            $tenant->run(fn () => $this->applyStatus($status));
        }

        // Always 200 once the signature is good: Meta retries on anything else,
        // and a payload we cannot interpret will not become interpretable.
        return response('', 200);
    }

    /**
     * HMAC-SHA256 of the RAW body, keyed with the app secret.
     *
     * The raw body matters — re-encoding the parsed JSON produces different
     * bytes and the signature will never match.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = config('whatsapp.webhook.app_secret');

        if (! $secret) {
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * Flatten Meta's nested envelope into [phone_number_id, status] pairs.
     *
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function statuses(Request $request): array
    {
        $out = [];

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                if (! is_string($phoneNumberId)) {
                    continue;
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    if (is_array($status) && isset($status['id'])) {
                        $out[] = [$phoneNumberId, $status];
                    }
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $status */
    private function applyStatus(array $status): void
    {
        $message = WhatsAppMessage::where('provider_message_id', $status['id'])->first();

        if (! $message) {
            return;
        }

        $timestamp = isset($status['timestamp'])
            ? now()->setTimestamp((int) $status['timestamp'])
            : now();

        match ($status['status'] ?? null) {
            'sent' => $message->update(['status' => 'sent', 'sent_at' => $message->sent_at ?? $timestamp]),
            'delivered' => $message->update(['status' => 'delivered', 'delivered_at' => $timestamp]),
            // Read implies delivered; Meta may skip the delivered callback.
            'read' => $message->update([
                'status' => 'read',
                'read_at' => $timestamp,
                'delivered_at' => $message->delivered_at ?? $timestamp,
            ]),
            'failed' => $message->update([
                'status' => 'failed',
                'error' => $status['errors'][0]['title'] ?? 'Delivery failed.',
            ]),
            default => null,
        };
    }
}
