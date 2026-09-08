<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — the Ministry rate a filing document was created under.
 *
 * Both tables already snapshot `lbp_per_usd`, the rate the business kept its
 * BOOKS at. That is the right figure for the business's own reporting and the
 * wrong one for a declaration: §3.6 requires foreign-currency amounts to reach
 * the Ministry at the PRESCRIBED rate.
 *
 * So a document now carries both rates it was created under, snapshotted for
 * the same reason the existing rate is snapshotted — the central row can later
 * be corrected, and a filed March figure must keep reproducing March's number
 * forever.
 *
 * Nullable, and nothing back-fills it: documents issued before this migration
 * genuinely have no prescribed rate recorded, and inventing one retroactively
 * would be fabricating a filing figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('mof_rate_lbp_per_usd')->nullable()->after('lbp_per_usd');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('mof_rate_lbp_per_usd')->nullable()->after('lbp_per_usd');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('mof_rate_lbp_per_usd');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('mof_rate_lbp_per_usd');
        });
    }
};
