<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — one payroll run per calendar month.
 *
 * The run SNAPSHOTS both the exchange rate and the NSSF rates it used. It does
 * not merely reference the central schedule row, because that row can later be
 * corrected — and a finalised March run must keep computing March's numbers
 * forever. `nssf_rate_schedule_id` is provenance only; there is deliberately
 * no foreign key, since the schedule lives in a different database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            // Whole LBP per 1 USD, as the rate is quoted in Lebanon.
            $table->unsignedBigInteger('lbp_per_usd');

            // Provenance, not a relationship — the schedule is in another DB.
            $table->unsignedBigInteger('nssf_rate_schedule_id')->nullable();

            // The snapshot. These are what the run actually computed with.
            $table->unsignedInteger('sickness_employer_bps');
            $table->unsignedInteger('sickness_employee_bps');
            $table->unsignedBigInteger('sickness_ceiling_lbp');
            $table->unsignedInteger('family_employer_bps');
            $table->unsignedBigInteger('family_ceiling_lbp');
            $table->unsignedInteger('eosi_employer_bps');

            $table->enum('status', ['draft', 'finalised'])->default('draft');
            $table->timestamp('finalised_at')->nullable();

            $table->timestamps();

            // One run per month. Re-running replaces the draft, never duplicates.
            $table->unique(['period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
