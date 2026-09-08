<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
 | Tenancy leaves real artefacts behind: each tenant gets its own database, and
 | under the sqlite test driver that is a file on disk. RefreshDatabase only
 | rolls back the CENTRAL connection, so tenant databases are torn down here.
 */
pest()->afterEach(function () {
    if (function_exists('tenancy') && tenant()) {
        tenancy()->end();
    }

    if (! app()->bound('db')) {
        return;
    }

    // Tenant::delete() fires TenantDeleted -> Jobs\DeleteDatabase.
    rescue(fn () => Tenant::all()->each->delete(), report: false);

    // Belt and braces: sweep any file a failed test left behind.
    rescue(fn () => collect(File::glob(database_path('tenant*.sqlite')))
        ->each(fn (string $path) => File::delete($path)), report: false);
});

/**
 * Provision a business the way `tenant:create` does, minus the console layer.
 */
function makeTenant(string $subdomain, array $attributes = []): Tenant
{
    $tenant = Tenant::create(array_merge([
        'name' => ucfirst($subdomain),
        'plan' => 'starter',
        'settings' => ['exchange_rate' => 89500, 'currency' => 'USD', 'language' => 'ar'],
    ], $attributes));

    $tenant->domains()->create(['domain' => $subdomain]);

    return $tenant;
}

/**
 * Create a user inside a tenant's own database.
 */
function makeTenantUser(Tenant $tenant, array $attributes = []): void
{
    $tenant->run(function () use ($attributes) {
        User::create(array_merge([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password-12345',
            'role' => 'owner',
            'locale' => 'ar',
        ], $attributes));
    });
}

/** Full URL for a tenant subdomain, e.g. http://acme.sme.test/login */
function tenantUrl(string $subdomain, string $path = '/'): string
{
    return 'http://'.$subdomain.'.'.config('tenancy.app_domain').$path;
}
