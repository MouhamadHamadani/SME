<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL database — the Meta phone number id for each business.
 *
 * Meta calls ONE webhook url for the whole app and identifies the business
 * only by this id, so it has to be a real indexed column: the webhook looks
 * up the tenant by it before it can enter tenant context at all. Storing it
 * inside the tenants JSON blob would make that lookup a table scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('wa_phone_number_id')->nullable()->unique()->after('wa_phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['wa_phone_number_id']);
            $table->dropColumn('wa_phone_number_id');
        });
    }
};
