<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — one line per employee per run.
 *
 * Amounts are stored in LBP because that is the currency NSSF is declared in,
 * whatever the salary was agreed in. The USD view is DERIVED from the run's
 * stored `lbp_per_usd`, not stored a second time: two stored copies of the
 * same figure eventually disagree, and then nobody knows which is the ledger.
 *
 * The as-contracted salary is kept alongside, so a USD-denominated salary
 * survives the round trip exactly rather than through a lira conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            // Denormalised so a payslip stays readable if the employee is renamed.
            $table->string('employee_name');
            $table->boolean('is_nssf_registered');

            // As contracted.
            $table->unsignedBigInteger('contracted_salary_minor');
            $table->enum('contracted_salary_currency', ['USD', 'LBP']);

            // As computed, all LBP.
            $table->unsignedBigInteger('gross_lbp');
            $table->unsignedBigInteger('sickness_base_lbp');
            $table->unsignedBigInteger('sickness_employer_lbp');
            $table->unsignedBigInteger('sickness_employee_lbp');
            $table->unsignedBigInteger('family_base_lbp');
            $table->unsignedBigInteger('family_employer_lbp');
            $table->unsignedBigInteger('eosi_base_lbp');
            $table->unsignedBigInteger('eosi_employer_lbp');

            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
