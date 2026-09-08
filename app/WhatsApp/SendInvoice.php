<?php

namespace App\WhatsApp;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Support\PhoneNumber;
use App\WhatsApp\Exceptions\WhatsAppException;

/**
 * Send an issued invoice to its customer on WhatsApp.
 *
 * The delivery row is written BEFORE the call and updated after, so a send
 * that crashes mid-flight leaves a `queued` row someone can see rather than
 * no trace at all. "Did the customer get it?" has to be answerable.
 */
class SendInvoice
{
    public function __construct(private readonly SenderFactory $senders) {}

    public function send(Invoice $invoice, Tenant $tenant): WhatsAppMessage
    {
        // A draft has no number, so there is nothing to tell the customer.
        if ($invoice->isDraft()) {
            throw WhatsAppException::notIssued();
        }

        $invoice->loadMissing('customer');
        $customer = $invoice->customer;

        $to = PhoneNumber::tryParse($customer->phone);

        if ($to === null) {
            throw filled($customer->phone)
                ? WhatsAppException::badRecipient((string) $customer->phone)
                : WhatsAppException::noRecipient($customer->name);
        }

        // Resolved before the row is written: an unconnected business should
        // not accumulate queued messages that can never be sent.
        $sender = $this->senders->for($tenant);

        $message = new TemplateMessage(
            to: $to,
            template: (string) config('whatsapp.invoice_template.name'),
            language: (string) config('whatsapp.invoice_template.language'),
            variables: $this->variables($invoice, $tenant),
        );

        $record = WhatsAppMessage::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'recipient' => $to->e164,
            'template' => $message->template,
            'language' => $message->language,
            'payload' => $message->toPayload(),
            'status' => 'queued',
        ]);

        try {
            $sent = $sender->send($message);
        } catch (WhatsAppException $e) {
            $record->update(['status' => 'failed', 'error' => $e->getMessage()]);

            throw $e;
        }

        $record->update([
            'status' => 'sent',
            'provider_message_id' => $sent->providerMessageId,
            'sent_at' => now(),
        ]);

        return $record->fresh();
    }

    /**
     * The template's {{1}}, {{2}}… in order.
     *
     * These must match the template APPROVED in Meta's Business Manager — the
     * count and order are part of what was approved, and a mismatch is
     * rejected at send time. See docs/WHATSAPP.md.
     *
     * @return array<int, string>
     */
    private function variables(Invoice $invoice, Tenant $tenant): array
    {
        return [
            $invoice->customer->name,
            $tenant->name,
            (string) $invoice->reference,
            $invoice->total()->format().' '.$invoice->currency->value,
            $invoice->due_on?->toDateString() ?? '—',
        ];
    }
}
