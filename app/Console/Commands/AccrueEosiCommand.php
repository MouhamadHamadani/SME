<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Payroll\Eosi\AccrueEosi;
use App\Payroll\Eosi\EosiStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Freeze the end-of-service estimate for every current employee.
 *
 *   php artisan eosi:accrue haddadgrill
 *   php artisan eosi:accrue haddadgrill --date=2026-12-31
 *
 * §9 calls this a "running estimate", which means it needs to be run — monthly
 * or at year end. Each run stores the strategy and formula version that
 * produced it, so a figure computed under today's law keeps saying what it
 * said after the law changes.
 */
class AccrueEosiCommand extends Command
{
    protected $signature = 'eosi:accrue
        {tenant : Business subdomain}
        {--date= : As-of date (default: today)}';

    protected $description = 'Record the end-of-service estimate for every employee';

    public function handle(AccrueEosi $accruals, EosiStrategy $strategy): int
    {
        $tenant = Domain::where('domain', $this->argument('tenant'))->first()?->tenant;

        if (! $tenant instanceof Tenant) {
            $this->components->error('No business with that subdomain.');

            return self::FAILURE;
        }

        $asOf = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();

        return $tenant->run(function () use ($accruals, $strategy, $asOf, $tenant) {
            [$accrued, $skipped] = $accruals->accrueAll($asOf);

            $this->components->info("End-of-service recorded for {$tenant->name}.");
            $this->components->twoColumnDetail('As of', $asOf->toDateString());
            $this->components->twoColumnDetail('Formula', $strategy->name().' '.$strategy->version());
            $this->components->twoColumnDetail('Employees accrued', (string) $accrued);

            if ($skipped > 0) {
                $this->components->twoColumnDetail('Skipped (under a month of service)', (string) $skipped);
            }

            return self::SUCCESS;
        });
    }
}
