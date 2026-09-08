<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — invoices.
 *
 * `number` is NULL while an invoice is a draft and is assigned only on issue.
 * That is what keeps the sequence gap-free: a tax invoice series may not have
 * holes, so a draft that is abandoned must never have consumed a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Assigned at issue. Unique, so a race between two people issuing
            // at the same moment fails loudly instead of duplicating a number.
            $table->unsignedInteger('number')->nullable()->unique();
            $table->string('reference')->nullable()->unique();

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->enum('status', ['draft', 'issued', 'paid', 'void'])->default('draft');
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('void_reason')->nullable();

            /*
             * The invoice is denominated in one currency and that is the tax
             * document. `lbp_per_usd` is the rate on the day it was issued,
             * stored so the LBP figures below can be reproduced years later.
             */
            $table->enum('currency', ['USD', 'LBP']);
            $table->unsignedBigInteger('lbp_per_usd')->nullable();

            // Totals in the invoice's own currency, summed from the lines.
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('vat_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);

            // The same totals in LBP, which is what the VAT return declares.
            $table->unsignedBigInteger('net_lbp')->default(0);
            $table->unsignedBigInteger('vat_lbp')->default(0);

            // Snapshot of the rate applied, for the same reason payroll and
            // VAT returns snapshot theirs.
            $table->unsignedInteger('vat_rate_bps')->nullable();
            $table->unsignedBigInteger('vat_rate_schedule_id')->nullable();

            // The link to the VAT return runs the other way: vat_transactions
            // carries invoice_id, because one invoice writes ONE TRANSACTION
            // PER VAT TREATMENT on it. A single row per invoice would report a
            // mixed invoice under one treatment and misstate the return.

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['status', 'issued_on']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
