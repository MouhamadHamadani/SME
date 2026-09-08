<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — every WhatsApp message this business sent.
 *
 * A delivery log, not a queue. It exists so "did the customer get the
 * invoice?" has an answer months later, and so a failed send is visible
 * rather than lost in a worker's stderr.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();

            // What it is about. Nullable so the log can grow beyond invoices.
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // E.164 digits, no plus — the form Meta accepts.
            $table->string('recipient', 20);
            $table->string('template');
            $table->string('language', 10);

            // What was actually sent, for an audit years later.
            $table->json('payload')->nullable();

            /*
             * queued  -> accepted by us, not yet handed to Meta
             * sent    -> Meta accepted it
             * delivered / read -> from the webhook
             * failed  -> Meta rejected it, or the send errored
             */
            $table->enum('status', ['queued', 'sent', 'delivered', 'read', 'failed'])->default('queued');

            // Meta's id (wamid...), which the delivery webhook reports against.
            $table->string('provider_message_id')->nullable()->index();
            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
