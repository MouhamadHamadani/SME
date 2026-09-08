<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — the people on one business's payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // NSSF registration number. Null until the business declares them.
            $table->string('nssf_number')->nullable();

            /*
             * Salary is stored in the currency it was AGREED in, as integer
             * minor units (USD cents, whole LBP). Post-crisis, Lebanese
             * salaries are commonly set in USD and paid partly in lira, so
             * converting to a single currency at write time would lose the
             * contract. NSSF is calculated in LBP at the payroll run's rate.
             */
            $table->unsignedBigInteger('base_salary_minor');
            $table->enum('base_salary_currency', ['USD', 'LBP']);

            /*
             * Dependents do not change the contribution — they decide the
             * family allowance NSSF pays BACK. Held here because the NSSF
             * declaration asks for them.
             */
            $table->boolean('has_dependent_spouse')->default(false);
            $table->unsignedTinyInteger('dependent_children')->default(0);

            /*
             * Undeclared staff are a fact of Lebanese SME life. Modelling it
             * explicitly is safer than someone faking it with zeroed rates.
             */
            $table->boolean('is_nssf_registered')->default(true);

            $table->date('started_on');
            $table->date('ended_on')->nullable();

            $table->timestamps();

            $table->index(['started_on', 'ended_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
