<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — when the employee was enrolled with the NSSF.
 *
 * §9 lists this on Employee explicitly, "needed for the EOSI seniority
 * coefficient", and §3.2 writes the coefficient bands in terms of years of
 * NSSF ENROLMENT — not years of service. They are two different clocks:
 * someone can work for three years before their employer declares them, and
 * Lebanese SMEs declaring staff late is common enough that the difference is
 * not hypothetical.
 *
 * Nullable, and null means "same as the start date", which is the correct
 * reading for an employee declared from day one. Back-filling a guess would
 * put a made-up enrolment date under someone's final payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('nssf_enrolled_on')->nullable()->after('started_on');
        });

        Schema::table('eosi_accruals', function (Blueprint $table) {
            // Stored beside service_months because the coefficient comes from
            // this one, and a stored estimate has to show its own working.
            $table->unsignedInteger('enrolment_months')->after('service_months');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('nssf_enrolled_on');
        });

        Schema::table('eosi_accruals', function (Blueprint $table) {
            $table->dropColumn('enrolment_months');
        });
    }
};
