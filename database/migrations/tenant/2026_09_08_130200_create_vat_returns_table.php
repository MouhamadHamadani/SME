<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — one quarterly VAT return.
 *
 * Snapshots the rate and filing rules it was computed under, for the same
 * reason payroll_runs does: a finalised Q1 must keep reporting Q1's numbers
 * even after the central schedule is corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_returns', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_quarter'); // 1-4

            // Provenance only — the schedule lives in another database.
            $table->unsignedBigInteger('vat_rate_schedule_id')->nullable();

            // Snapshot.
            $table->unsignedInteger('standard_rate_bps');
            $table->unsignedSmallInteger('filing_deadline_days');
            $table->date('due_on');

            // Computed totals, LBP.
            $table->unsignedBigInteger('taxable_sales_lbp')->default(0);
            $table->unsignedBigInteger('exempt_sales_lbp')->default(0);
            $table->unsignedBigInteger('output_vat_lbp')->default(0);
            $table->unsignedBigInteger('purchases_lbp')->default(0);
            $table->unsignedBigInteger('input_vat_lbp')->default(0);

            /*
             * A period where input exceeds output produces a credit rather
             * than a payment. Standard VAT mechanics carry that credit into
             * the next period, so it is stored on both ends: what came in,
             * and what is left over. Signed, because the net position is
             * genuinely negative when you are in credit.
             */
            $table->bigInteger('credit_brought_forward_lbp')->default(0);
            $table->bigInteger('net_position_lbp')->default(0);
            $table->bigInteger('payable_lbp')->default(0);
            $table->bigInteger('credit_carried_forward_lbp')->default(0);

            $table->enum('status', ['draft', 'finalised'])->default('draft');
            $table->timestamp('finalised_at')->nullable();

            $table->timestamps();

            $table->unique(['period_year', 'period_quarter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_returns');
    }
};
