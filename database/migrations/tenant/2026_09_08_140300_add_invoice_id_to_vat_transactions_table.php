<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — link a VAT transaction back to the invoice that wrote it.
 *
 * The relationship is one invoice to MANY transactions, one per VAT treatment
 * on the invoice. A single "dominant treatment" transaction per invoice
 * misreports every mixed invoice: a clinic billing standard-rated
 * consultations alongside exempt procedures would have the exempt amount
 * counted as taxable turnover. See docs/INVOICING.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_transactions', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('vat_return_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('vat_transactions', function (Blueprint $table) {
            $table->dropIndex(['invoice_id']);
            $table->dropColumn('invoice_id');
        });
    }
};
