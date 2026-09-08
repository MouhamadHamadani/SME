<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\get;

/*
 | Regression cover for a whole class of bug that the test defaults hide.
 |
 | The test suite runs SESSION_DRIVER=array and CACHE_STORE=array, so nothing
 | here ever touched the `sessions` or `cache` tables. In development — where
 | both are `database` — every authenticated page load 500'd, because tenancy
 | repoints `database.default` at the tenant and those tables live centrally.
 |
 | The rule: infrastructure tables (sessions, cache, jobs) are CENTRAL. Business
 | data is per-tenant. These tests pin that rule down.
 */

it('pins session, cache and queue to the central connection while tenancy is active', function () {
    $tenant = makeTenant('acme');
    tenancy()->initialize($tenant);

    // Sanity: tenancy really is active and the default connection has moved.
    expect(config('database.default'))->toBe('tenant');

    foreach ([
        'session.connection',
        'cache.stores.database.connection',
        'queue.connections.database.connection',
    ] as $key) {
        // null means "use the default connection" — which is now the tenant's.
        // That null IS the bug, so assert it is explicitly set and not tenant.
        expect(config($key))->not->toBeNull("{$key} must be pinned to the central connection")
            ->and(config($key))->not->toBe('tenant');
    }
});

it('does not put infrastructure tables in a tenant database', function () {
    $tenant = makeTenant('acme');

    $tenant->run(function () {
        expect(Schema::hasTable('users'))->toBeTrue()
            ->and(Schema::hasTable('sessions'))->toBeFalse()
            ->and(Schema::hasTable('cache'))->toBeFalse()
            ->and(Schema::hasTable('jobs'))->toBeFalse()
            ->and(Schema::hasTable('tenants'))->toBeFalse()
            ->and(Schema::hasTable('leads'))->toBeFalse();
    });
});

it('keeps business tables out of the central database', function () {
    expect(Schema::hasTable('tenants'))->toBeTrue()
        ->and(Schema::hasTable('domains'))->toBeTrue()
        ->and(Schema::hasTable('leads'))->toBeTrue()
        ->and(Schema::hasTable('sessions'))->toBeTrue()
        // Users belong to a business, never to the central database.
        ->and(Schema::hasTable('users'))->toBeFalse();
});

it('serves an authenticated tenant page with database-backed sessions and cache', function () {
    // The functional version of the bug above: array drivers never hit a table.
    config([
        'session.driver' => 'database',
        'cache.default' => 'database',
    ]);

    $tenant = makeTenant('acme', ['name' => 'Acme Trading']);
    makeTenantUser($tenant, ['email' => 'rami@acme.test', 'name' => 'Rami']);

    tenancy()->initialize($tenant);

    $this->actingAs(User::sole())
        ->get(tenantUrl('acme', '/'))
        ->assertOk()
        ->assertSee('Acme Trading', false);
});

it('serves the public marketing site with database-backed sessions', function () {
    config(['session.driver' => 'database', 'cache.default' => 'database']);

    get('http://sme.test/')->assertOk();
});
