<?php

namespace App\Console\Commands;

use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Payroll\Exceptions\PayrollException;
use App\Payroll\RunPayroll;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Draft or finalise a month's payroll for one business.
 *
 *   php artisan payroll:run haddadgrill 2026 3 --rate=89500
 *   php artisan payroll:run haddadgrill 2026 3 --finalise
 */
class RunPayrollCommand extends Command
{
    protected $signature = 'payroll:run
        {tenant : Business subdomain}
        {year}
        {month}
        {--rate= : LBP per 1 USD for this period; defaults to the business setting}
        {--finalise : Lock the run — requires verified NSSF rates}';

    protected $description = 'Draft or finalise a payroll run for one business';

    public function handle(RunPayroll $runPayroll): int
    {
        $tenant = $this->resolveTenant($this->argument('tenant'));

        if (! $tenant) {
            $this->components->error('No business with that subdomain.');

            return self::FAILURE;
        }

        $year = (int) $this->argument('year');
        $month = (int) $this->argument('month');

        if ($month < 1 || $month > 12) {
            $this->components->error('Month must be 1-12.');

            return self::FAILURE;
        }

        $rate = (int) ($this->option('rate') ?: ($tenant->settings['exchange_rate'] ?? 0));

        if ($rate <= 0) {
            $this->components->error(
                'No LBP/USD rate. Pass --rate, or set one on the business.'
            );

            return self::FAILURE;
        }

        return $tenant->run(function () use ($runPayroll, $year, $month, $rate, $tenant) {
            try {
                $run = $runPayroll->forMonth($year, $month, $rate);

                if ($this->option('finalise')) {
                    $run->finalise();
                }
            } catch (PayrollException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->report($run, $tenant->name);

            return self::SUCCESS;
        });
    }

    private function report(PayrollRun $run, string $business): void
    {
        $this->components->info("{$business} — payroll {$run->periodLabel()} ({$run->status})");

        $this->table(
            ['Employee', 'Gross LBP', 'Employee NSSF', 'Employer NSSF', 'Net LBP'],
            $run->lines->map(fn ($line) => [
                $line->employee_name.($line->is_nssf_registered ? '' : ' (undeclared)'),
                $line->gross()->format(),
                $line->employeeDeductions()->format(),
                $line->employerContributions()->format(),
                $line->net()->format(),
            ])->all()
        );

        $this->components->twoColumnDetail('Rate applied', number_format($run->lbp_per_usd).' LBP / USD');
        $this->components->twoColumnDetail('Gross', $run->totalGross()->format().' LBP');
        $this->components->twoColumnDetail('Employee contributions', $run->totalEmployeeDeductions()->format().' LBP');
        $this->components->twoColumnDetail('Employer contributions', $run->totalEmployerContributions()->format().' LBP');
        $this->components->twoColumnDetail('<options=bold>Total cost to the business</>', '<options=bold>'.$run->totalEmployerCost()->format().' LBP</>');

        if (! $run->isFinalised()) {
            $this->newLine();
            $this->components->warn('Draft. Re-running this month will rebuild it.');
        }
    }

    private function resolveTenant(string $subdomain): ?Tenant
    {
        return Domain::where('domain', $subdomain)->first()?->tenant;
    }
}
