<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\VatReturn;
use App\Vat\Exceptions\VatException;
use App\Vat\PrepareVatReturn;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Draft or file one quarter's VAT return.
 *
 *   php artisan vat:return arzclinic 2026 1
 *   php artisan vat:return arzclinic 2026 1 --file
 */
class VatReturnCommand extends Command
{
    protected $signature = 'vat:return
        {tenant : Business subdomain}
        {year}
        {quarter : 1-4}
        {--file : Finalise the return — requires verified VAT rates}';

    protected $description = 'Draft or file a quarterly VAT return';

    public function handle(PrepareVatReturn $prepare): int
    {
        $tenant = Domain::where('domain', $this->argument('tenant'))->first()?->tenant;

        if (! $tenant instanceof Tenant) {
            $this->components->error('No business with that subdomain.');

            return self::FAILURE;
        }

        $year = (int) $this->argument('year');
        $quarter = (int) $this->argument('quarter');

        return $tenant->run(function () use ($prepare, $year, $quarter, $tenant) {
            try {
                $return = $prepare->forQuarter($year, $quarter);

                if ($this->option('file')) {
                    $return->finalise();
                    $return->refresh();
                }
            } catch (VatException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->report($return, $tenant->name);

            return self::SUCCESS;
        });
    }

    private function report(VatReturn $r, string $business): void
    {
        $this->components->info("{$business} — VAT {$r->periodLabel()} ({$r->status})");

        $this->components->twoColumnDetail('Period', $r->start()->toDateString().' to '.$r->end()->toDateString());
        $this->components->twoColumnDetail('Due', $r->due_on->toDateString());
        $this->newLine();

        $this->components->twoColumnDetail('Taxable sales', number_format($r->taxable_sales_lbp).' LBP');
        $this->components->twoColumnDetail('Exempt sales', number_format($r->exempt_sales_lbp).' LBP');
        $this->components->twoColumnDetail('Output VAT', number_format($r->output_vat_lbp).' LBP');
        $this->components->twoColumnDetail('Purchases', number_format($r->purchases_lbp).' LBP');
        $this->components->twoColumnDetail('Input VAT (claimed)', number_format($r->input_vat_lbp).' LBP');

        if ($r->credit_brought_forward_lbp > 0) {
            $this->components->twoColumnDetail('Credit brought forward', number_format($r->credit_brought_forward_lbp).' LBP');
        }

        $this->newLine();

        if ($r->isInCredit()) {
            $this->components->twoColumnDetail(
                '<options=bold>Credit carried forward</>',
                '<options=bold>'.number_format($r->credit_carried_forward_lbp).' LBP</>'
            );
        } else {
            $this->components->twoColumnDetail(
                '<options=bold>Payable</>',
                '<options=bold>'.number_format($r->payable_lbp).' LBP</>'
            );
        }

        if (! $r->isFinalised()) {
            $this->newLine();
            $this->components->warn('Draft. Re-running this quarter will rebuild it.');
        }
    }
}
