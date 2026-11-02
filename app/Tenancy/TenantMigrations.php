<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which tenant databases are behind the tenant migrations on disk.
 *
 * "In sync" is the SET of applied migration names, never the batch number.
 * Batches are not comparable across databases: a tenant created last week has
 * every migration in batch 1, an older one has them spread over several.
 */
final class TenantMigrations
{
    /** Set by deploy:migrate while it fans out, so the bare-`migrate` guard stays quiet. */
    public static bool $deploying = false;

    /** @return list<string> */
    public static function onDisk(): array
    {
        return array_keys(app('migrator')->getMigrationFiles(config('tenancy.migration_parameters.--path')));
    }

    /** @return list<string> */
    public static function centralOnDisk(): array
    {
        $migrator = app('migrator');

        return array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths())));
    }

    /**
     * Applied migrations on the CURRENT default connection — central, or the
     * tenant whose context is initialised.
     *
     * @return list<string>
     */
    public static function applied(): array
    {
        $table = config('database.migrations.table');

        return Schema::hasTable($table)
            ? DB::table($table)->orderBy('migration')->pluck('migration')->all()
            : [];
    }

    /**
     * stancl's run() does not restore context if the callback throws; callers
     * that catch must end tenancy themselves.
     *
     * @return list<string>
     */
    public static function appliedIn(Tenant $tenant): array
    {
        return $tenant->run(fn () => self::applied());
    }

    /** @return list<string> */
    public static function pending(Tenant $tenant): array
    {
        return array_values(array_diff(self::onDisk(), self::appliedIn($tenant)));
    }

    /**
     * Oldest first, so a deploy that stopped part-way resumes where it stopped.
     *
     * @return \Illuminate\Support\Collection<int, Tenant>
     */
    public static function tenants()
    {
        if (! Schema::connection(config('tenancy.database.central_connection'))->hasTable((new Tenant)->getTable())) {
            return collect();
        }

        return Tenant::query()->orderBy('created_at')->orderBy('id')->get();
    }

    /** @return array<string, list<string>> tenant id => its pending migrations */
    public static function behind(): array
    {
        $behind = [];

        foreach (self::tenants() as $tenant) {
            if ($pending = self::pending($tenant)) {
                $behind[$tenant->getTenantKey()] = $pending;
            }
        }

        return $behind;
    }
}
