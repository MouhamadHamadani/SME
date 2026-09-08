<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL database — VAT rates and filing rules, effective-dated.
 *
 * Same contract as nssf_rate_schedules: national law, held once, verified by a
 * human before anything can be filed against it. Nothing is seeded.
 *
 * The standard rate (11% since 2018) is stable enough to be a command DEFAULT,
 * but the registration threshold has moved with the currency collapse and is
 * required explicitly — see docs/VAT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_rate_schedules', function (Blueprint $table) {
            $table->id();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Integer basis points: 1100 = 11.00%.
            $table->unsignedInteger('standard_rate_bps');

            // Turnover above which registration is compulsory, whole LBP.
            $table->unsignedBigInteger('registration_threshold_lbp');

            // Returns are quarterly; the deadline is this many days after the
            // period ends. Held as data because it is a filing rule, not a
            // constant, and the compliance screen counts down against it.
            $table->unsignedSmallInteger('filing_deadline_days');

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
        Schema::dropIfExists('vat_rate_schedules');
    }
};
