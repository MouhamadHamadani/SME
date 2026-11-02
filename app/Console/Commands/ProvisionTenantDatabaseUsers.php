<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\TenantMigrations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\DatabaseConfig;
use Throwable;

/**
 * One-time: move tenants created before per-tenant MySQL users onto their own.
 *
 *   php artisan tenants:provision-db-users
 *
 * New tenants get their user at creation (ScopedMySQLDatabaseManager). This is
 * for the ones still connecting with the central credential. Safe against a
 * live estate, and safe to run again:
 *
 *  - A tenant whose own user exists and connects is left alone.
 *  - The new user is created, granted and PROVEN to connect before the tenant
 *    row changes. A request already running keeps its connection; the next one
 *    reads the row and gets the new credential. At no point does the stored
 *    credential name a user that does not exist.
 *  - The username derives from the tenant key, so a run that died between
 *    CREATE USER and saving finds its own leftover and replaces it.
 *
 * It also repairs: a tenant whose stored credential stopped working (a
 * password lost to an APP_KEY rotated without APP_PREVIOUS_KEYS) is
 * re-provisioned the same way.
 */
class ProvisionTenantDatabaseUsers extends Command
{
    protected $signature = 'tenants:provision-db-users';

    protected $description = 'Give each tenant database its own MySQL user (for tenants created before per-tenant users)';

    public function handle(): int
    {
        $failed = 0;

        foreach (TenantMigrations::tenants() as $tenant) {
            $id = $tenant->getTenantKey();
            $manager = $tenant->database()->manager();

            if (! $manager instanceof ManagesDatabaseUsers) {
                $this->components->twoColumnDetail($id, 'skipped: this database driver has no users');

                continue;
            }

            try {
                if ($this->hasWorkingOwnUser($tenant, $manager)) {
                    $this->components->twoColumnDetail($id, 'already scoped');

                    continue;
                }

                $this->provision($tenant, $manager);
            } catch (Throwable $e) {
                $failed++;
                Log::error('tenants:provision-db-users: failed', ['tenant' => $id, 'error' => $e->getMessage()]);
                $this->components->twoColumnDetail($id, '<fg=red;options=bold>FAILED</>');
                $this->components->error($e->getMessage());

                continue;
            }

            Log::info('tenants:provision-db-users: provisioned', ['tenant' => $id, 'username' => $tenant->database()->getUsername()]);
            $this->components->twoColumnDetail($id, '<fg=green>provisioned</>');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function hasWorkingOwnUser(Tenant $tenant, ManagesDatabaseUsers $manager): bool
    {
        $username = $tenant->database()->getUsername();

        return $username !== null && $manager->userExists($username) && $this->connects($tenant);
    }

    private function provision(Tenant $tenant, ManagesDatabaseUsers $manager): void
    {
        // A copy, so the stored tenant keeps a working credential until the new one is proven.
        $draft = clone $tenant;
        $draft->setInternal('db_username', $tenant->database()->getUsername() ?? (DatabaseConfig::$usernameGenerator)($tenant));
        $draft->setInternal('db_password', (DatabaseConfig::$passwordGenerator)($tenant));

        // Clears a leftover from a run that died before saving, or a user whose password was lost.
        $manager->deleteUser($draft->database());
        $manager->createUser($draft->database());

        if (! $this->connects($draft)) {
            $manager->deleteUser($draft->database());

            throw new RuntimeException('The new MySQL user could not connect, so it was removed. The tenant is unchanged.');
        }

        $tenant->setInternal('db_username', $draft->getInternal('db_username'));
        $tenant->setInternal('db_password', $draft->getInternal('db_password'));
        $tenant->save();
    }

    /** Log in with the tenant's stored credential and read its own schema. */
    private function connects(Tenant $tenant): bool
    {
        try {
            config(['database.connections.tenant_probe' => $tenant->database()->connection()]);
            DB::connection('tenant_probe')->table(config('database.migrations.table'))->exists();

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            DB::purge('tenant_probe');
        }
    }
}
