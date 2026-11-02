<?php

namespace App\Tenancy;

use Illuminate\Database\QueryException;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;

/**
 * Every tenant database gets its own MySQL user, granted on that schema only,
 * so MySQL itself refuses one business's connection access to another's data.
 * Without it the separation was only the application picking the right
 * database name over a root connection.
 *
 * stancl's manager does the CREATE USER and GRANT. This closes three gaps
 * found in its v3.10.1 source — see docs/TENANCY-HARDENING-STATUS.md.
 */
class ScopedMySQLDatabaseManager extends PermissionControlledMySQLDatabaseManager
{
    /**
     * stancl interpolates the password into the statement, and QueryException
     * puts the statement in its message — which Laravel then logs and prints.
     * A failed CREATE USER would write the credential to storage/logs.
     */
    public function createUser(DatabaseConfig $databaseConfig): bool
    {
        try {
            return parent::createUser($databaseConfig);
        } catch (QueryException $e) {
            throw new RuntimeException(sprintf(
                'Could not create MySQL user [%s] for database [%s] (MySQL error %s). The statement is withheld because it contains the password.',
                $databaseConfig->getUsername(),
                $databaseConfig->getName(),
                $e->errorInfo[1] ?? $e->getCode(),
            ));
        }
    }

    /**
     * A tenant provisioned before per-tenant users has no username until
     * `tenants:provision-db-users` runs. stancl would issue
     * DROP USER IF EXISTS '' — the anonymous user — for it.
     */
    public function deleteUser(DatabaseConfig $databaseConfig): bool
    {
        $username = $databaseConfig->getUsername();

        if (! $username) {
            return true;
        }

        return $this->database()->statement("DROP USER IF EXISTS `{$username}`@`%`");
    }

    /**
     * The user goes FIRST. MySQL keeps a user's grants after its database is
     * dropped, so a user that outlives its schema gets into any database later
     * created under the same name. stancl drops it second, and not at all if
     * DROP DATABASE throws.
     */
    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $this->deleteUser($tenant->database());

        return MySQLDatabaseManager::deleteDatabase($tenant);
    }

    /**
     * Derived from the tenant key, not random, so a provisioning run that died
     * after CREATE USER but before saving the tenant can find its own leftover
     * and replace it — and `mysql.user` can be read against `tenants`.
     * MySQL caps usernames at 32 characters: "t_" plus 30 of the UUID's hex.
     */
    public static function usernameFor(TenantWithDatabase $tenant): string
    {
        return 't_'.substr(str_replace('-', '', (string) $tenant->getTenantKey()), 0, 30);
    }
}
