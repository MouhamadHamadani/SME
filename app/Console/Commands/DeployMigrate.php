<?php

namespace App\Console\Commands;

use App\Tenancy\TenantMigrations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The deploy step for schema changes. Never bare `migrate` in a deploy.
 *
 *   php artisan deploy:migrate
 *
 * `migrate` reaches the central database only, and every business has its own.
 * This migrates central, then each tenant in turn, inside maintenance mode,
 * logging every tenant — and does not call it done until every tenant holds
 * the same applied migrations.
 *
 * Resuming is re-running. Tenants are visited oldest first and one with nothing
 * pending is skipped, so after a failure at tenant 137 the next run passes over
 * 1–136 without touching them and starts at 137. No state file to go stale.
 *
 * Maintenance mode: if this run put the app down, it brings it back up on
 * success. If the app was already down — the deploy script did it, or a
 * previous run failed — it is left down for the operator.
 */
class DeployMigrate extends Command
{
    protected $signature = 'deploy:migrate';

    protected $description = 'Migrate the central database and every tenant database, in maintenance mode';

    public function handle(): int
    {
        $wasDown = $this->laravel->isDownForMaintenance();

        if (! $wasDown) {
            $this->call('down');
        }

        TenantMigrations::$deploying = true;

        try {
            $ok = $this->migrateCentral() && $this->migrateTenants() && $this->assertInSync();
        } finally {
            TenantMigrations::$deploying = false;
        }

        if (! $ok) {
            $this->components->error('Stopped. The app stays in maintenance mode. Fix the cause, run `php artisan deploy:migrate` again (tenants already migrated are skipped), then `php artisan up`.');

            return self::FAILURE;
        }

        if ($wasDown) {
            $this->components->warn('The app was already in maintenance mode when this started, so it is left there. Run `php artisan up` when ready.');
        } else {
            $this->call('up');
        }

        return self::SUCCESS;
    }

    private function migrateCentral(): bool
    {
        try {
            if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                throw new RuntimeException('migrate exited non-zero.');
            }
        } catch (Throwable $e) {
            Log::error('deploy:migrate: central failed', ['error' => $e->getMessage()]);
            $this->components->error('Central: '.$e->getMessage());

            return false;
        }

        return true;
    }

    private function migrateTenants(): bool
    {
        $migrated = $current = 0;

        foreach (TenantMigrations::tenants() as $tenant) {
            $id = $tenant->getTenantKey();

            try {
                $pending = TenantMigrations::pending($tenant);

                if ($pending === []) {
                    $current++;

                    continue;
                }

                if ($this->call('tenants:migrate', ['--tenants' => [$id], '--force' => true]) !== self::SUCCESS) {
                    throw new RuntimeException('tenants:migrate exited non-zero.');
                }
            } catch (Throwable $e) {
                // stancl leaves the failed tenant initialised.
                tenancy()->end();

                Log::error('deploy:migrate: tenant failed', ['tenant' => $id, 'error' => $e->getMessage()]);
                $this->components->twoColumnDetail($id, '<fg=red;options=bold>FAILED</>');
                $this->components->error($e->getMessage());

                return false;
            }

            $migrated++;
            Log::info('deploy:migrate: tenant migrated', ['tenant' => $id, 'migrations' => $pending]);
            $this->components->twoColumnDetail($id, '<fg=green>'.count($pending).' migrated</>');
        }

        Log::info('deploy:migrate: tenants done', ['migrated' => $migrated, 'already_current' => $current]);
        $this->components->info("Tenants: {$migrated} migrated, {$current} already current.");

        return true;
    }

    /**
     * Central has nothing pending; every tenant has nothing pending and holds
     * the SAME applied set as the others. Sets, not batch numbers — see
     * TenantMigrations.
     */
    private function assertInSync(): bool
    {
        $problems = [];

        if ($pending = array_diff(TenantMigrations::centralOnDisk(), TenantMigrations::applied())) {
            $problems[] = 'Central still has pending migrations: '.implode(', ', $pending);
        }

        $onDisk = TenantMigrations::onDisk();
        $variants = [];

        foreach (TenantMigrations::tenants() as $tenant) {
            $applied = TenantMigrations::appliedIn($tenant);

            if ($pending = array_diff($onDisk, $applied)) {
                $problems[] = "Tenant {$tenant->getTenantKey()} still has pending migrations: ".implode(', ', $pending);
            }

            $variants[implode("\n", $applied)][] = $tenant->getTenantKey();
        }

        if (count($variants) > 1) {
            uasort($variants, fn (array $a, array $b) => count($b) <=> count($a));

            $problems[] = 'Tenants not on the same migrations as the rest: '
                .implode(', ', array_merge(...array_slice(array_values($variants), 1)));
        }

        if ($problems !== []) {
            Log::error('deploy:migrate: estate out of sync after the run', ['problems' => $problems]);

            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return false;
        }

        $this->components->info('Every tenant is on the same migrations, and central is current.');

        return true;
    }
}
