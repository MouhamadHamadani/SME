<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — payments received against issued invoices.
 *
 * One payment belongs to exactly ONE invoice. That is a deliberate limit for
 * now, not a modelling accident: a customer clearing several open invoices in
 * one visit is the obvious next step, and it arrives as a
 * `payment_allocations` pivot with every row here becoming the trivial
 * one-allocation case. Which is why the payment is its own entity carrying its
 * own currency, rate, method and receipt number rather than a set of columns
 * bolted onto `invoices` — that shape would have to be unpicked; this one is
 * an additive migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();

            /*
             * Sequential and gap-free, allocated under lockForUpdate exactly
             * as invoice and credit-note numbers are, with the unique index as
             * the second line of defence. A receipt is a document somebody is
             * handed; two of them carrying one number is a problem.
             *
             * There is no separate `reference` string column: the printed
             * "RCT-2026-0007" is derived from this number and the receipt's
             * own year, the same shape as INV-/CN-.
             */
            $table->unsignedInteger('receipt_number')->unique();

            /*
             * The payment's own currency. It must match the invoice's — a
             * mismatch is refused rather than converted, because converting it
             * silently would decide an exchange rate nobody agreed to. Held as
             * its own column anyway, because a payment is its own transaction
             * and losing the currency it was made in is the mistake the whole
             * Money layer exists to prevent.
             */
            $table->unsignedBigInteger('amount_minor');
            $table->enum('currency', ['USD', 'LBP']);

            // The MARKET rate on the day the money arrived, snapshotted like
            // payroll runs and invoices snapshot theirs. Null for an LBP
            // payment, which needs no conversion.
            $table->unsignedBigInteger('lbp_per_usd')->nullable();

            // String + a PHP enum cast, NOT a database enum: adding a payment
            // rail must be one case in App\Support\PaymentMethod.
            $table->string('method');

            // Cheque number, Whish/OMT transaction ID, bank reference.
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            // When the money actually arrived — not when somebody typed it in.
            $table->date('received_on');

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['invoice_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
