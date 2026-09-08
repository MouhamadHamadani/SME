<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL database — NSSF contribution rates, effective-dated.
 *
 * Central because these are national law, identical for every business, and
 * the product's core promise is that WE track the changes rather than each
 * customer maintaining their own copy. One row per period of validity.
 *
 * Nothing here is seeded with a guess. `verified_at` records that a human
 * checked the figures against the actual decree; a payroll run cannot be
 * finalised against unverified rates. Rates that are merely plausible are more
 * dangerous than absent rates, because they compute confidently and wrongly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nssf_rate_schedules', function (Blueprint $table) {
            $table->id();

            // Half-open period: effective_to null means "still in force".
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Integer basis points: 850 = 8.50%. Never decimals — see Money.
            $table->unsignedInteger('sickness_employer_bps');
            $table->unsignedInteger('sickness_employee_bps');
            $table->unsignedBigInteger('sickness_ceiling_lbp');

            $table->unsignedInteger('family_employer_bps');
            $table->unsignedBigInteger('family_ceiling_lbp');

            // End-of-service has no ceiling by law, so no column for one.
            $table->unsignedInteger('eosi_employer_bps');

            // Provenance. Which decree, dated when, seen where.
            $table->text('source_note')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by')->nullable();

            $table->timestamps();

            $table->unique('effective_from');
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nssf_rate_schedules');
    }
};
