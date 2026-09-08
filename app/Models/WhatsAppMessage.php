<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp message this business sent. A delivery log, not a queue.
 */
class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'invoice_id',
        'customer_id',
        'recipient',
        'template',
        'language',
        'payload',
        'status',
        'provider_message_id',
        'error',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        // Either document — an invoice or a credit note.
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('document_type');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recipientNumber(): PhoneNumber
    {
        return PhoneNumber::parse($this->recipient);
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /** Meta only ever moves a message forward: sent -> delivered -> read. */
    public function isDelivered(): bool
    {
        return in_array($this->status, ['delivered', 'read'], true);
    }

    /** filed / due-soon / overdue — the shared status-badge vocabulary. */
    public function statusKey(): string
    {
        return match ($this->status) {
            'delivered', 'read' => 'filed',
            'failed' => 'overdue',
            default => 'due-soon',
        };
    }
}
