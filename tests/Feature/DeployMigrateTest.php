<?php

use App\Tenancy\TenantMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/*
 | deploy:migrate — the tenant-aware deploy step — and the guard that stops a
 | bare `migrate` from passing for one. Runs on SQLite and on MySQL.
 |
 | Each test adds one extra tenant migration (the "probe") AFTER its tenants
 | exist, so they start one migration behind: the state a release leaves an
 | estate in.
 */

beforeEach(function () {
    // In memory, not storage/framework/down: a test that dies mid-run must not
    // leave the app down and 503 every HTTP test after it.
    config(['app.maintenance.driver' => 'array']);

    $this->probeDir = storage_path('framework/testing/deploy-probe');
    File::ensureDirectoryExists($this->probeDir);
    File::put($this->probeDir.'/2099_01_01_000000_deploy_probe.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                if (tenant('id') === config('probe.fail')) {
                    throw new RuntimeException('Simulated migration failure');
                }

                Schema::create('deploy_probe', fn (Blueprint $table) => $table->id());

                config()->push('probe.runs', ['tenant' => tenant('id'), 'down' => app()->isDownForMaintenance()]);
            }

            public function down(): void
            {
                Schema::dropIfExists('deploy_probe');
            }
        };
        PHP);
});

afterEach(function () {
    File::deleteDirectory($this->probeDir);
});

/** Tenants a minute apart, so deploy order is creation order. */
function estate(string ...$subdomains): array
{
    return array_map(function (string $subdomain) {
        test()->travel(1)->minutes();

        return makeTenant($subdomain);
    }, $subdomains);
}

/** Ship the probe: from now on every tenant is one migration behind. */
function shipProbe(): void
{
    config(['tenancy.migration_parameters.--path' => [database_path('migrations/tenant'), test()->probeDir]]);
}

/** @return list<string> tenant ids the probe ran for, in order */
function probeRuns(): array
{
    return collect(config('probe.runs', []))->pluck('tenant')->all();
}

it('migrates every tenant inside maintenance mode and logs each one', function () {
    [$a, $b, $c] = estate('alpha', 'bravo', 'charlie');
    shipProbe();
    Log::spy();

    expect(Artisan::call('deploy:migrate'))->toBe(0)
        ->and(probeRuns())->toBe([$a->id, $b->id, $c->id])
        // Down while it ran, up again afterwards.
        ->and(collect(config('probe.runs'))->pluck('down')->unique()->all())->toBe([true])
        ->and(app()->isDownForMaintenance())->toBeFalse();

    foreach ([$a, $b, $c] as $tenant) {
        expect(TenantMigrations::pending($tenant))->toBe([]);

        Log::shouldHaveReceived('info')->with('deploy:migrate: tenant migrated', Mockery::on(
            fn (array $context) => $context['tenant'] === $tenant->id
                && $context['migrations'] === ['2099_01_01_000000_deploy_probe'],
        ));
    }
});

it('stops at the failing tenant, stays down, and resumes there without re-running the rest', function () {
    [$a, $b, $c] = estate('alpha', 'bravo', 'charlie');
    shipProbe();
    config(['probe.fail' => $b->id]);
    Log::spy();

    expect(Artisan::call('deploy:migrate'))->toBe(1)
        ->and(Artisan::output())->toContain($b->id)->toContain('Simulated migration failure')
        // Alpha done, bravo failed, charlie never attempted.
        ->and(probeRuns())->toBe([$a->id])
        ->and(TenantMigrations::pending($c))->toBe(['2099_01_01_000000_deploy_probe'])
        // The estate is on two schemas: the app must not come back up.
        ->and(app()->isDownForMaintenance())->toBeTrue();

    Log::shouldHaveReceived('error')->with('deploy:migrate: tenant failed', Mockery::on(
        fn (array $context) => $context['tenant'] === $b->id && str_contains($context['error'], 'Simulated'),
    ));

    // The cause is fixed; run it again.
    config(['probe.fail' => null]);

    expect(Artisan::call('deploy:migrate'))->toBe(0)
        // Alpha ran once in total: the resume skipped it.
        ->and(probeRuns())->toBe([$a->id, $b->id, $c->id])
        // The failed run put the app down, so bringing it up is the operator's call.
        ->and(app()->isDownForMaintenance())->toBeTrue()
        ->and(Artisan::output())->toContain('php artisan up');
});

it('fails the run when tenants still disagree afterwards', function () {
    [$a, $b] = estate('alpha', 'bravo');
    // A migration applied to one tenant by hand, which the code knows nothing about.
    $b->run(fn () => DB::table('migrations')->insert(['migration' => '2099_12_31_000000_applied_by_hand', 'batch' => 99]));

    expect(Artisan::call('deploy:migrate'))->toBe(1)
        ->and(Artisan::output())->toContain('not on the same migrations')
        ->and(app()->isDownForMaintenance())->toBeTrue();
});

it('succeeds with no tenants at all', function () {
    expect(Artisan::call('deploy:migrate'))->toBe(0)
        ->and(app()->isDownForMaintenance())->toBeFalse();
});

it('fails a bare `migrate` that leaves tenant databases behind', function () {
    [$a] = estate('alpha');

    // Every tenant current: bare migrate is fine.
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);

    shipProbe();

    // "Nothing to migrate" on central used to exit 0 here, with alpha behind.
    expect(fn () => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'php artisan deploy:migrate');
    expect(TenantMigrations::pending($a))->toBe(['2099_01_01_000000_deploy_probe']);
});
