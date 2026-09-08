<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — one line per item on an invoice.
 *
 * VAT is computed PER LINE and then summed, not applied to the invoice
 * subtotal. The two give different answers once rounding is involved, and
 * per-line is what a tax invoice shows — so it is what the totals must agree
 * with, line by line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('position')->default(0);
            $table->string('description');

            /*
             * Quantity in thousandths, so 2.5 hours is 2500. An integer,
             * because 0.1 + 0.2 has no place on an invoice line either.
             */
            $table->unsignedBigInteger('quantity_milli');

            // Unit price and computed amounts, all in the invoice's currency.
            $table->unsignedBigInteger('unit_price_minor');
            $table->enum('treatment', ['standard', 'zero_rated', 'exempt']);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('vat_minor');

            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
