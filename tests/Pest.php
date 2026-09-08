<?php

use App\Models\NssfRateSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRateSchedule;
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

/**
 * An NSSF rate schedule in the CENTRAL database.
 *
 * The figures are TEST FIXTURES, not a claim about the real ceilings — those
 * are data the system is given, never something it assumes. See docs/PAYROLL.md.
 */
function schedule(array $overrides = []): NssfRateSchedule
{
    return NssfRateSchedule::create(array_merge([
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'sickness_employer_bps' => 800,
        'sickness_employee_bps' => 300,
        'sickness_ceiling_lbp' => 2_500_000,
        'family_employer_bps' => 600,
        'family_ceiling_lbp' => 1_500_000,
        'eosi_employer_bps' => 850,
        'source_note' => 'Test fixture',
    ], $overrides));
}

/** The same, already checked off against a decree — required to finalise a run. */
function verified(array $overrides = []): NssfRateSchedule
{
    return schedule(array_merge(['verified_at' => now(), 'verified_by' => 'Tester'], $overrides));
}

/**
 * A VAT rate schedule in the CENTRAL database.
 *
 * 11% is a TEST FIXTURE, like the NSSF figures above — the system is told its
 * rates, it never assumes them. See docs/VAT.md.
 */
function vatSchedule(array $overrides = []): VatRateSchedule
{
    return VatRateSchedule::create(array_merge([
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'standard_rate_bps' => 1100,
        'registration_threshold_lbp' => 100_000_000,
        'filing_deadline_days' => 20,
        'source_note' => 'Test fixture',
    ], $overrides));
}

/** The same, signed off — required before a return can be filed. */
function vatVerified(array $overrides = []): VatRateSchedule
{
    return vatSchedule(array_merge(['verified_at' => now(), 'verified_by' => 'Tester'], $overrides));
}

/** Full URL for a tenant subdomain, e.g. http://acme.sme.test/login */
function tenantUrl(string $subdomain, string $path = '/'): string
{
    return 'http://'.$subdomain.'.'.config('tenancy.app_domain').$path;
}
