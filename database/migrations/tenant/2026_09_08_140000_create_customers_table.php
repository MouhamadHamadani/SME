<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT database — who a business invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // WhatsApp is how invoices reach people here, so it is the primary
            // channel rather than a secondary contact field.
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // A B2B customer's VAT number belongs on the tax invoice. Without
            // it the buyer cannot reclaim the input VAT.
            $table->string('vat_number')->nullable();

            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
