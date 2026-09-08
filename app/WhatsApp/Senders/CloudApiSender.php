<?php

namespace App\WhatsApp\Senders;

use App\WhatsApp\Contracts\WhatsAppSender;
use App\WhatsApp\Exceptions\WhatsAppException;
use App\WhatsApp\SentMessage;
use App\WhatsApp\TemplateMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta's WhatsApp Cloud API.
 *
 * Credentials are PER BUSINESS: each tenant connects its own number, so they
 * are passed in rather than read from config. A platform-wide token would let
 * one business's invoices go out from another's number.
 *
 * NOTE: the endpoint shape and template payload follow Meta's Cloud API as of
 * the pinned `api_version`. Confirm both against current docs before going
 * live — Meta changes them between versions, and a wrong shape fails at send
 * time rather than at deploy time. See docs/WHATSAPP.md.
 */
class CloudApiSender implements WhatsAppSender
{
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $accessToken,
    ) {}

    public function send(TemplateMessage $message): SentMessage
    {
        $url = sprintf(
            '%s/%s/%s/messages',
            rtrim((string) config('whatsapp.base_url'), '/'),
            config('whatsapp.api_version'),
            $this->phoneNumberId,
        );

        $payload = $message->toPayload();

        try {
            $response = Http::withToken($this->accessToken)
                ->acceptJson()
                ->timeout(15)
                /*
                 * Retry only a dropped connection, and `throw: false` so a
                 * rejection comes back as a RESPONSE to inspect. Left at the
                 * default, retry() throws RequestException and Meta's actual
                 * error message — the only useful part — never reaches the
                 * person who clicked send.
                 */
                ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw WhatsAppException::unreachable($e->getMessage());
        }

        if ($response->failed()) {
            throw WhatsAppException::rejected($this->errorFrom($response));
        }

        $id = $response->json('messages.0.id');

        if (! is_string($id) || $id === '') {
            throw WhatsAppException::rejected('the API returned no message id.');
        }

        return new SentMessage($id, $payload);
    }

    /**
     * Confirm the credentials work, WITHOUT sending anything.
     *
     * A settings form that only stores what was pasted is a form that
     * discovers a typo when a customer's invoice fails. This is a read-only
     * GET against the number itself: a wrong id, an expired token or a token
     * belonging to a different business all fail here, in front of the person
     * who can fix them.
     *
     * @return array{display_phone_number: string, verified_name: string}
     */
    public function verify(): array
    {
        $url = sprintf(
            '%s/%s/%s',
            rtrim((string) config('whatsapp.base_url'), '/'),
            config('whatsapp.api_version'),
            $this->phoneNumberId,
        );

        try {
            $response = Http::withToken($this->accessToken)
                ->acceptJson()
                ->timeout(15)
                ->get($url, ['fields' => 'display_phone_number,verified_name']);
        } catch (ConnectionException $e) {
            throw WhatsAppException::unreachable($e->getMessage());
        }

        if ($response->failed()) {
            throw WhatsAppException::rejected($this->errorFrom($response));
        }

        $number = $response->json('display_phone_number');

        if (! is_string($number) || $number === '') {
            // A 200 with no number means the id is not a phone number at all
            // — a WABA id pasted into the wrong box is the usual cause.
            throw WhatsAppException::rejected('that id did not return a phone number.');
        }

        return [
            'display_phone_number' => $number,
            'verified_name' => (string) ($response->json('verified_name') ?? ''),
        ];
    }

    /** Meta nests the useful part; the raw body is the fallback. */
    private function errorFrom(Response $response): string
    {
        $detail = $response->json('error.message')
            ?? $response->json('error.error_user_msg')
            ?? $response->body();

        return is_string($detail) ? $detail : (string) json_encode($detail);
    }
}
