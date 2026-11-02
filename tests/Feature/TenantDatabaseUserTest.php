<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\ScopedMySQLDatabaseManager;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;

/*
 |--------------------------------------------------------------------------
 | MYSQL ONLY:  ./vendor/bin/pest -c phpunit.mysql.xml
 |--------------------------------------------------------------------------
 | Per-tenant MySQL users, and MySQL itself — no application code in the
 | path — refusing one tenant's credential access to another tenant's schema.
 |
 | SQLite has no users and no grants. Under the default phpunit.xml every test
 | here that calls onlyOnMysql() is SKIPPED, not passed: a green SQLite run
 | proves nothing about tenant isolation at the database layer.
 */

function onlyOnMysql(): void
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped('Needs real MySQL users and grants: ./vendor/bin/pest -c phpunit.mysql.xml');
    }
}

/** A connection logged in as the tenant's own MySQL user, outside tenancy. */
function connectAs(Tenant $tenant): Connection
{
    DB::purge('as_tenant');
    config(['database.connections.as_tenant' => $tenant->database()->connection()]);

    return DB::connection('as_tenant');
}

function mysqlUserExists(string $username): bool
{
    return DB::connection(config('tenancy.database.central_connection'))
        ->table('mysql.user')->where('user', $username)->exists();
}

function currentMysqlUser(Tenant $tenant): string
{
    return $tenant->run(fn () => DB::selectOne('select current_user() as u')->u);
}

/** A tenant as it existed before per-tenant users: on the central credential. */
function legacyTenant(string $subdomain): Tenant
{
    $scoped = config('tenancy.database.managers.mysql');
    config(['tenancy.database.managers.mysql' => MySQLDatabaseManager::class]);

    try {
        return makeTenant($subdomain);
    } finally {
        config(['tenancy.database.managers.mysql' => $scoped]);
    }
}

it('wires MySQL and MariaDB tenants to the scoped manager', function () {
    expect(config('tenancy.database.managers.mysql'))->toBe(ScopedMySQLDatabaseManager::class)
        ->and(config('tenancy.database.managers.mariadb'))->toBe(ScopedMySQLDatabaseManager::class);
});

// -- Provisioning ------------------------------------------------------------

it('gives a new tenant its own MySQL user, and the app connects as it', function () {
    onlyOnMysql();
    $tenant = makeTenant('alpha');
    $username = $tenant->database()->getUsername();

    expect($username)->toBe(ScopedMySQLDatabaseManager::usernameFor($tenant))
        ->and(mysqlUserExists($username))->toBeTrue()
        ->and(currentMysqlUser($tenant))->toBe("{$username}@%");
});

it('grants that user its own schema and nothing else', function () {
    onlyOnMysql();
    $tenant = makeTenant('alpha');
    $username = $tenant->database()->getUsername();

    $grants = collect(DB::select("SHOW GRANTS FOR `{$username}`@`%`"))
        ->map(fn (object $row) => array_values((array) $row)[0]);

    expect($grants)->toHaveCount(2)
        ->and($grants[0])->toStartWith('GRANT USAGE ON *.*')
        ->and($grants[1])->toEndWith('ON `'.$tenant->database()->getName()."`.* TO `{$username}`@`%`");
});

it('is refused another tenant’s schema by MySQL itself', function () {
    onlyOnMysql();
    $alpha = makeTenant('alpha');
    $bravo = makeTenant('bravo');
    makeTenantUser($bravo, ['email' => 'owner@bravo.test']);

    $asAlpha = connectAs($alpha);

    // Positive control: the credential works on alpha's own schema...
    expect($asAlpha->table('users')->count())->toBe(0);

    // ...and MySQL denies the same credential bravo's.
    try {
        $asAlpha->select('select * from `'.$bravo->database()->getName().'`.`users`');
        $this->fail('MySQL allowed a cross-tenant read.');
    } catch (QueryException $e) {
        // 1142 table access denied / 1044 database access denied.
        expect($e->errorInfo[1])->toBeIn([1142, 1044]);
    }

    expect(collect($asAlpha->select('show databases'))->pluck('Database'))
        ->not->toContain($bravo->database()->getName());
});

it('stores the password encrypted and never serialises the credential', function () {
    onlyOnMysql();
    $tenant = makeTenant('alpha');
    $password = $tenant->database()->getPassword();

    $raw = json_decode(DB::table('tenants')->where('id', $tenant->id)->value('data'), true)['tenancy_db_password'];

    expect($password)->not->toBeEmpty()
        ->and($raw)->not->toBe($password)
        ->and(Crypt::decryptString($raw))->toBe($password)
        ->and($tenant->toArray())->not->toHaveKey('tenancy_db_password')
        ->and($tenant->toArray())->not->toHaveKey('tenancy_db_username');
});

it('keeps the password out of the exception when CREATE USER fails', function () {
    onlyOnMysql();
    // A quote breaks the statement, and MySQL's own 1064 message then echoes
    // the SQL after it ("near '…'") — the password included.
    $secret = Str::random(24);
    DatabaseConfig::generatePasswordsUsing(fn () => "it's-{$secret}");
    $id = (string) Str::uuid();

    try {
        makeTenant('alpha', ['id' => $id]);
        $this->fail('Tenant creation should have failed on the broken statement.');
    } catch (RuntimeException $e) {
        // QueryException is a RuntimeException too, so an unwrapped one lands here and fails below.
        expect($e->getMessage())->toContain(ScopedMySQLDatabaseManager::usernameFor(Tenant::find($id)))
            ->toContain('1064')
            ->not->toContain($secret)
            ->and($e->getPrevious())->toBeNull();
    } finally {
        DatabaseConfig::$passwordGenerator = null; // back to stancl's default on next use
    }
});

// -- Deletion ----------------------------------------------------------------

it('drops the user when the tenant is deleted', function () {
    onlyOnMysql();
    $tenant = makeTenant('alpha');
    $username = $tenant->database()->getUsername();

    $tenant->delete();

    expect(mysqlUserExists($username))->toBeFalse();
});

it('drops the user even when the database is already gone', function () {
    onlyOnMysql();
    $tenant = makeTenant('alpha');
    $username = $tenant->database()->getUsername();
    DB::statement('DROP DATABASE `'.$tenant->database()->getName().'`');

    expect(fn () => $tenant->delete())->toThrow(QueryException::class)
        ->and(mysqlUserExists($username))->toBeFalse();
});

// -- Existing tenants: tenants:provision-db-users ----------------------------

it('moves an existing tenant onto its own user, exactly once', function () {
    onlyOnMysql();
    $tenant = legacyTenant('legacy');
    makeTenantUser($tenant);

    expect($tenant->database()->getUsername())->toBeNull()
        ->and(currentMysqlUser($tenant))->toStartWith(config('database.connections.mysql.username').'@');

    expect(Artisan::call('tenants:provision-db-users'))->toBe(0)
        ->and(Artisan::output())->toContain('provisioned');

    $tenant = $tenant->fresh();
    $username = $tenant->database()->getUsername();
    $password = $tenant->database()->getPassword();

    expect($username)->toBe(ScopedMySQLDatabaseManager::usernameFor($tenant))
        ->and(currentMysqlUser($tenant))->toBe("{$username}@%")
        // Its data is still there, read as the new user.
        ->and($tenant->run(fn () => User::count()))->toBe(1);

    // Again: nothing to do, nothing re-provisioned.
    expect(Artisan::call('tenants:provision-db-users'))->toBe(0)
        ->and(Artisan::output())->toContain('already scoped')->not->toContain('provisioned');

    $again = $tenant->fresh();

    expect($again->database()->getUsername())->toBe($username)
        ->and($again->database()->getPassword())->toBe($password)
        ->and(DB::table('mysql.user')->where('user', $username)->count())->toBe(1);
});

it('replaces a leftover user from a run that died before saving the tenant', function () {
    onlyOnMysql();
    $tenant = legacyTenant('legacy');
    $username = ScopedMySQLDatabaseManager::usernameFor($tenant);

    // What a crash between CREATE USER and saving the tenant leaves behind.
    DB::statement("CREATE USER `{$username}`@`%` IDENTIFIED BY 'lost-with-the-crash'");

    expect(Artisan::call('tenants:provision-db-users'))->toBe(0);

    $tenant = $tenant->fresh();

    expect($tenant->database()->getUsername())->toBe($username)
        ->and(currentMysqlUser($tenant))->toBe("{$username}@%");
});

it('leaves tenants that already have a working user alone, on any driver', function () {
    $tenant = makeTenant('alpha');
    $before = $tenant->fresh()->getAttributes();

    expect(Artisan::call('tenants:provision-db-users'))->toBe(0)
        ->and(Artisan::output())->not->toContain('provisioned')
        ->and($tenant->fresh()->getAttributes())->toBe($before);
});
