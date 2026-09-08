<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — bonuses and overtime, dated.
 *
 * This table exists FOR EOSI. §3.2 excludes overtime and bonuses from the
 * indemnity base unless they were paid consistently for the preceding three
 * years or more, in which case they are included — and that carve-out cannot
 * be applied to data that was never recorded. Without a dated history of what
 * was paid and when, "consistently for three years" is unanswerable.
 *
 * DELIBERATE BOUNDARY: these elements do NOT feed the monthly payroll run or
 * its NSSF contributions. Adding pay elements to payroll proper is a separate
 * piece of work (docs/PAYROLL.md §6) that would change every existing run's
 * figures. This table is read by the EOSI accrual and by nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_pay_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            /*
             * Only the two kinds §3.2 names. An open string would invite
             * "13th month" and "transport" to be filed here, and each of those
             * has its own indemnity treatment that this code does not model.
             */
            $table->enum('kind', ['bonus', 'overtime']);

            // Integer minor units in the currency it was actually paid in —
            // the same rule as salaries. Never converted at write time.
            $table->unsignedBigInteger('amount_minor');
            $table->enum('amount_currency', ['USD', 'LBP']);

            // The date that decides which year it counts toward.
            $table->date('paid_on');

            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_pay_elements');
    }
};
