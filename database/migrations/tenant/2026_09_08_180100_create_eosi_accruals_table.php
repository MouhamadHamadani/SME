<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — the running end-of-service estimate per employee (§9).
 *
 * This is the employee's ENTITLEMENT, which is not the same thing as the
 * employer's 8.5% contribution to the NSSF. The contribution is what the
 * business pays into the fund each month and is already modelled on
 * `payroll_lines`. This is what the employee is owed on leaving. They are
 * computed differently, they diverge, and conflating them is the whole reason
 * the fund's balance and the liability are different numbers.
 *
 * FORMULA-VERSIONED, per §9. Every row records which strategy produced it and
 * under which version. Law 319 could move EOSI from a lump sum to a monthly
 * pension, and when it does, historical estimates must keep saying what they
 * said — recomputing them under the new law would silently rewrite the past.
 *
 * `inputs` holds the working: coefficient, service months, the elements that
 * qualified for the §3.2 three-year carve-out and why. An estimate a person
 * cannot audit is an estimate they cannot defend to a departing employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eosi_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // The date the estimate was computed AS OF, not when it was run.
            $table->date('as_of');

            // Which calculation produced this, and its version. Both stored,
            // never inferred — see the class docblock above.
            $table->string('strategy');
            $table->string('formula_version');

            $table->unsignedInteger('service_months');
            // Seniority coefficient in basis points: 5000 = 50%.
            $table->unsignedInteger('coefficient_bps');

            // The 12-month average that the coefficient was applied to, in the
            // currency the salary is contracted in.
            $table->unsignedBigInteger('average_salary_minor');
            $table->enum('average_salary_currency', ['USD', 'LBP']);

            // The estimate itself, in the same currency. Never converted at
            // write time — the contract currency is the answer.
            $table->unsignedBigInteger('accrued_minor');
            $table->enum('accrued_currency', ['USD', 'LBP']);

            // The working, for audit.
            $table->json('inputs')->nullable();

            $table->timestamps();

            // One estimate per employee per as-of date. Recomputing corrects
            // that day's figure rather than appending a second answer.
            $table->unique(['employee_id', 'as_of']);
            $table->index('as_of');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eosi_accruals');
    }
};
