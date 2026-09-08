<?php

use App\Invoicing\IssueInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\WhatsAppMessage;
use App\Support\PhoneNumber;
use App\WhatsApp\Exceptions\WhatsAppException;
use App\WhatsApp\Senders\CloudApiSender;
use App\WhatsApp\SendInvoice;
use App\WhatsApp\TemplateMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 | Every test here fakes HTTP. Nothing reaches Meta, and no real number is
 | ever messaged — see docs/WHATSAPP.md.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tenant = makeTenant('acme', ['name' => 'Acme Trading']);
});

// -- Phone numbers ----------------------------------------------------------

it('normalises the ways a Lebanese number gets written', function () {
    // All of these are the same number. Meta accepts exactly one form.
    foreach ([
        '03 123 456', '+961 3 123 456', '0096131 23456', '961 3 123456', '3123456',
    ] as $input) {
        expect(PhoneNumber::parse($input)->e164)->toBe('9613123456');
    }
});

it('keeps a number that already has another country code', function () {
    expect(PhoneNumber::parse('+33 6 12 34 56 78')->e164)->toBe('33612345678');
});

it('formats a number back for a person to read', function () {
    expect(PhoneNumber::parse('03 123 456')->forDisplay())->toBe('+9613123456');
});

it('rejects something that is not a number', function () {
    expect(fn () => PhoneNumber::parse('not a phone'))->toThrow(InvalidArgumentException::class);
    expect(fn () => PhoneNumber::parse('12'))->toThrow(InvalidArgumentException::class);
    expect(PhoneNumber::tryParse(''))->toBeNull();
    expect(PhoneNumber::tryParse('rubbish'))->toBeNull();
});

// -- The payload ------------------------------------------------------------

it('builds the template payload Meta expects', function () {
    $message = new TemplateMessage(
        to: PhoneNumber::parse('03 123 456'),
        template: 'invoice_notification',
        language: 'ar',
        variables: ['Beirut Foods', 'Acme Trading', 'INV-2026-0001'],
    );

    expect($message->toPayload())->toBe([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        // Digits, no plus.
        'to' => '9613123456',
        'type' => 'template',
        'template' => [
            'name' => 'invoice_notification',
            'language' => ['code' => 'ar'],
            'components' => [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => 'Beirut Foods'],
                    ['type' => 'text', 'text' => 'Acme Trading'],
                    ['type' => 'text', 'text' => 'INV-2026-0001'],
                ],
            ]],
        ],
    ]);
});

it('omits the components block when a template takes no variables', function () {
    $message = new TemplateMessage(PhoneNumber::parse('03 123 456'), 'hello', 'en');

    expect($message->toPayload()['template'])->not->toHaveKey('components');
});

// -- The Cloud API sender ---------------------------------------------------

it('posts to the phone number id endpoint with a bearer token', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ABC']]])]);

    $sent = (new CloudApiSender('PHONE_ID_1', 'secret-token'))
        ->send(new TemplateMessage(PhoneNumber::parse('03 123 456'), 'invoice_notification', 'ar'));

    expect($sent->providerMessageId)->toBe('wamid.ABC');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/PHONE_ID_1/messages')
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request['to'] === '9613123456'
            && $request['type'] === 'template';
    });
});

it('surfaces Meta\'s own error message when a send is rejected', function () {
    Http::fake(['*' => Http::response([
        'error' => ['message' => 'Template name does not exist in the translation'],
    ], 400)]);

    expect(fn () => (new CloudApiSender('PHONE_ID_1', 'token'))
        ->send(new TemplateMessage(PhoneNumber::parse('03 123 456'), 'nope', 'ar')))
        ->toThrow(WhatsAppException::class, 'Template name does not exist');
});

it('fails loudly when the API returns no message id', function () {
    Http::fake(['*' => Http::response(['messages' => []])]);

    expect(fn () => (new CloudApiSender('PHONE_ID_1', 'token'))
        ->send(new TemplateMessage(PhoneNumber::parse('03 123 456'), 'x', 'ar')))
        ->toThrow(WhatsAppException::class, 'no message id');
});

// -- Sending an invoice -----------------------------------------------------

/** An issued invoice with a customer who has a phone number. */
function invoiceForWhatsApp(?string $phone = '03 123 456'): Invoice
{
    $customer = Customer::factory()->create(['name' => 'Beirut Foods', 'phone' => $phone]);
    $invoice = draftInvoice(null, ['customer_id' => $customer->id]);

    return app(IssueInvoice::class)
        ->issue($invoice, Carbon::parse('2026-02-10'));
}

it('logs a delivery row and never leaves the machine on the log driver', function () {
    config(['whatsapp.driver' => 'log']);
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = invoiceForWhatsApp();
        $message = app(SendInvoice::class)->send($invoice, $this->tenant);

        expect($message->status)->toBe('sent')
            ->and($message->recipient)->toBe('9613123456')
            ->and($message->provider_message_id)->toStartWith('wamid.LOG-')
            ->and($message->invoice_id)->toBe($invoice->id)
            ->and($message->payload['template']['name'])->toBe(config('whatsapp.invoice_template.name'));
    });

    // preventStrayRequests would have failed the test if anything had been sent.
    Http::assertNothingSent();
});

it('sends through the Cloud API when the business is connected', function () {
    config(['whatsapp.driver' => 'cloud']);
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REAL']]])]);
    vatSchedule();

    $this->tenant->connectWhatsApp('PHONE_ID_9', 'tenant-token');

    $this->tenant->run(function () {
        $message = app(SendInvoice::class)->send(invoiceForWhatsApp(), $this->tenant);

        expect($message->status)->toBe('sent')
            ->and($message->provider_message_id)->toBe('wamid.REAL');
    });

    // The tenant's OWN credentials, not a platform-wide one.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/PHONE_ID_9/')
        && $r->hasHeader('Authorization', 'Bearer tenant-token'));
});

it('refuses to send when the business has not connected a number', function () {
    config(['whatsapp.driver' => 'cloud']);
    vatSchedule();

    $this->tenant->run(function () {
        $invoice = invoiceForWhatsApp();

        expect(fn () => app(SendInvoice::class)->send($invoice, $this->tenant))
            ->toThrow(WhatsAppException::class, 'has not connected a WhatsApp number');

        // And nothing is queued that could never be sent.
        expect(WhatsAppMessage::count())->toBe(0);
    });
});

it('records a failure rather than losing it', function () {
    config(['whatsapp.driver' => 'cloud']);
    Http::fake(['*' => Http::response(['error' => ['message' => 'Recipient not opted in']], 400)]);
    vatSchedule();

    $this->tenant->connectWhatsApp('PHONE_ID_9', 'token');

    $this->tenant->run(function () {
        expect(fn () => app(SendInvoice::class)->send(invoiceForWhatsApp(), $this->tenant))
            ->toThrow(WhatsAppException::class);

        $message = WhatsAppMessage::sole();

        expect($message->status)->toBe('failed')
            ->and($message->error)->toContain('Recipient not opted in');
    });
});

it('refuses a draft, a customer with no phone, and an unusable number', function () {
    config(['whatsapp.driver' => 'log']);
    vatSchedule();

    $this->tenant->run(function () {
        expect(fn () => app(SendInvoice::class)->send(draftInvoice(), $this->tenant))
            ->toThrow(WhatsAppException::class, 'Only an issued invoice');

        expect(fn () => app(SendInvoice::class)->send(invoiceForWhatsApp(null), $this->tenant))
            ->toThrow(WhatsAppException::class, 'no phone number');

        expect(fn () => app(SendInvoice::class)->send(invoiceForWhatsApp('n/a'), $this->tenant))
            ->toThrow(WhatsAppException::class, 'not a usable WhatsApp number');
    });
});

it('keeps one business\'s message log out of another\'s', function () {
    config(['whatsapp.driver' => 'log']);
    vatSchedule();
    $globex = makeTenant('globex');

    $this->tenant->run(fn () => app(SendInvoice::class)->send(invoiceForWhatsApp(), $this->tenant));

    $globex->run(fn () => expect(WhatsAppMessage::count())->toBe(0));
});
