<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — VAT amounts become SIGNED.
 *
 * A credit note is a negative sale. Its VAT transaction carries negative net
 * and VAT, so the return's existing sums reduce output VAT naturally rather
 * than needing a parallel "credits" code path that could drift from the
 * "sales" one.
 *
 * The return's own totals go signed for the same reason: a quarter where
 * credits exceed sales is unusual but legitimate (a large cancellation), and
 * an unsigned column would fail the write rather than report the position.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_transactions', function (Blueprint $table) {
            $table->bigInteger('amount_minor')->change();
            $table->bigInteger('net_lbp')->change();
            $table->bigInteger('vat_lbp')->change();
        });

        Schema::table('vat_returns', function (Blueprint $table) {
            $table->bigInteger('taxable_sales_lbp')->default(0)->change();
            $table->bigInteger('exempt_sales_lbp')->default(0)->change();
            $table->bigInteger('output_vat_lbp')->default(0)->change();
            $table->bigInteger('purchases_lbp')->default(0)->change();
            $table->bigInteger('input_vat_lbp')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('vat_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_minor')->change();
            $table->unsignedBigInteger('net_lbp')->change();
            $table->unsignedBigInteger('vat_lbp')->change();
        });

        Schema::table('vat_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('taxable_sales_lbp')->default(0)->change();
            $table->unsignedBigInteger('exempt_sales_lbp')->default(0)->change();
            $table->unsignedBigInteger('output_vat_lbp')->default(0)->change();
            $table->unsignedBigInteger('purchases_lbp')->default(0)->change();
            $table->unsignedBigInteger('input_vat_lbp')->default(0)->change();
        });
    }
};
