<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central database. One row per customer business.
 *
 * Each tenant gets its OWN DATABASE rather than a tenant_id column on shared
 * tables. For this product that is a compliance decision, not a scaling one: a
 * business's payroll, NSSF contributions and employee records are separated at
 * the database level, so a missing `where tenant_id = ?` cannot leak one
 * business's salaries into another's. It also makes "export/delete everything
 * for this business" a single operation.
 *
 * `data` is stancl's JSON overflow column — anything not listed in
 * Tenant::getCustomColumns() lands there (including `settings`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('name');
            // Published pricing tiers, §7. Mirrors lang/*/marketing.php.
            $table->enum('plan', Tenant::PLANS)->default('starter');
            // WhatsApp sender for invoice delivery. Null until they connect one.
            $table->string('wa_phone_number')->nullable();

            $table->timestamps();
            $table->json('data')->nullable();

            $table->index('plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
