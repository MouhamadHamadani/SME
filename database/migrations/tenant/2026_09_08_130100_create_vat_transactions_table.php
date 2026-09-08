<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — the sales and purchases a VAT return is built from.
 *
 * There is no invoicing module yet, so this is the book of record for VAT.
 * When invoicing lands, an invoice should WRITE one of these rather than the
 * return learning to read two sources.
 *
 * Amounts are LBP (VAT is declared in lira) with the as-transacted currency
 * kept alongside, exactly as payroll_lines does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_transactions', function (Blueprint $table) {
            $table->id();

            // Sale -> output VAT (owed). Purchase -> input VAT (recoverable).
            $table->enum('direction', ['sale', 'purchase']);
            $table->enum('treatment', ['standard', 'zero_rated', 'exempt']);

            $table->date('transacted_on');
            $table->string('description');
            $table->string('counterparty')->nullable();
            // The other party's VAT number: an input claim needs a valid
            // tax invoice, and this is what makes it one.
            $table->string('counterparty_vat_number')->nullable();

            // As transacted.
            $table->unsignedBigInteger('amount_minor');
            $table->enum('amount_currency', ['USD', 'LBP']);
            $table->unsignedBigInteger('lbp_per_usd')->nullable();

            // As computed, LBP. gross = net + vat, never stored separately.
            $table->unsignedBigInteger('net_lbp');
            $table->unsignedBigInteger('vat_lbp');

            /*
             * Input VAT is only recoverable when the purchase relates to
             * taxable supplies. A partly-exempt business has to apportion, and
             * that rule is NOT modelled — this is the bookkeeper's flag so the
             * system never silently over-recovers. See docs/VAT.md.
             */
            $table->boolean('is_recoverable')->default(true);

            // Set when a finalised return has claimed this line, so it cannot
            // be edited or counted twice.
            $table->foreignId('vat_return_id')->nullable();

            $table->timestamps();

            $table->index(['transacted_on', 'direction']);
            $table->index('vat_return_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_transactions');
    }
};
