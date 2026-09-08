<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — credit notes live in the `invoices` table.
 *
 * They are structurally the same document: a customer, dated lines, a currency
 * and rate, a VAT snapshot, a number, and immutability once issued. Splitting
 * them into a parallel table would duplicate all of that and let the two drift.
 *
 * `document_type` separates them, and each model applies a GLOBAL SCOPE for
 * its own type — so existing Invoice queries cannot accidentally pick up a
 * credit note, which is the failure mode this arrangement has to rule out.
 *
 * Numbering is per series: INV-… and CN-… each start at 1, hence the unique
 * index moving from `number` alone to (document_type, number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('document_type', ['invoice', 'credit_note'])
                ->default('invoice')
                ->after('id');

            // The invoice this credit note corrects.
            $table->foreignId('credited_invoice_id')->nullable()->after('customer_id');

            $table->index(['document_type', 'status']);
            $table->index('credited_invoice_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->unique(['document_type', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['document_type', 'number']);
            $table->unique('number');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['document_type', 'status']);
            $table->dropIndex(['credited_invoice_id']);
            $table->dropColumn(['document_type', 'credited_invoice_id']);
        });
    }
};
