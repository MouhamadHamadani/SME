<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pre-launch lead capture.
     *
     * Deliberately NO tenant_id: a lead exists before a tenant does. When
     * billing lands (Whish/OMT, Phase 2 per §6) a converted lead will point at
     * a tenant, but that is a later column on a later migration — not a reason
     * to pull this table inside tenancy now.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('business_name');
            $table->string('phone');
            $table->string('email');
            $table->string('business_type');
            $table->string('currently_using')->nullable();
            $table->string('locale', 5)->default('en');
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
