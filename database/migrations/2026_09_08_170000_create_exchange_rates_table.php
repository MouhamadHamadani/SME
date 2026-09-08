<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL database — the two LBP/USD rates, one row per day.
 *
 * Lebanon has TWO official answers to "what is a dollar worth today", and they
 * are used for different purposes:
 *
 *   market — what the market says. The business's own books.
 *   MoF    — what the Ministry of Finance prescribes. Filings only.
 *
 * Reporting a foreign-currency salary to the MoF at the market rate, or
 * keeping the books at the prescribed rate, are both wrong. Storing one number
 * and calling it "the rate" makes that mistake unrepresentable-in-reverse: you
 * cannot ask which rate applies, so you cannot notice you used the wrong one.
 *
 * CENTRAL, like nssf_rate_schedules and vat_rate_schedules, because both
 * figures are national facts rather than business decisions. 250 businesses
 * should not each retype the government's number, and must not be able to
 * disagree about what it was.
 *
 * `tenants.settings.exchange_rate` is a different thing and is untouched: the
 * rate a business CHOOSES to book at. See docs/DUAL-CURRENCY-STATUS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();

            $table->date('rate_date');

            /*
             * Whole LBP per 1 USD, as the rate is quoted in Lebanon. Integers,
             * never floats — these multiply salaries and tax figures.
             *
             * Two independent columns with no derivation between them. Neither
             * defaults to the other: a day where only one is known must read
             * as "the other is unknown", not as "they are equal".
             */
            $table->unsignedBigInteger('market_rate_lbp_per_usd')->nullable();
            $table->unsignedBigInteger('mof_rate_lbp_per_usd')->nullable();

            // Where the figures came from. Required in practice — a rate with
            // no provenance cannot be defended to an auditor.
            $table->text('source')->nullable();

            /*
             * The same human gate NSSF and VAT rates carry. The MoF rate feeds
             * filings, and a wrong one misstates a declaration, so nothing can
             * be filed against an unverified rate.
             */
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by')->nullable();

            $table->timestamps();

            // One row per day. Entering a day twice corrects it, never duplicates.
            $table->unique('rate_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
