<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL database.
 *
 * Sessions stay central even though users are per-tenant. The public marketing
 * site runs on the central connection and still needs a session store (CSRF on
 * the lead form), so a tenant-only sessions table would break it.
 *
 * `user_id` is only meaningful alongside the tenant the request resolved to —
 * the subdomain determines the tenant before the auth guard reads the session,
 * so a session from one tenant cannot authenticate against another's users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
