<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Enter the day's two LBP/USD rates, and verify them.
 *
 *   php artisan exchange:rate --date=2026-09-08 --market=89500 --mof=89500 \
 *       --source="BDL circular 151 / parallel market close"
 *   php artisan exchange:rate --verify=12 --by="Rima Haddad"
 *   php artisan exchange:rate --list
 *
 * OPERATOR-ONLY, deliberately. Both figures are national — one is quoted by
 * the market, the other prescribed by the Ministry — so they are entered once
 * centrally, exactly like `nssf:schedule` and `vat:schedule`. A tenant-facing
 * form here would let one business edit the rate every other business files
 * at, which is not a warning to write, it is a hole not to open.
 *
 * Businesses set their own BOOKING rate in the app, which is a different thing
 * — see docs/DUAL-CURRENCY-STATUS.md.
 */
class ExchangeRateCommand extends Command
{
    protected $signature = 'exchange:rate
        {--date= : The day these rates apply to (default: today)}
        {--market= : Market rate, whole LBP per 1 USD}
        {--mof= : Ministry-prescribed rate, whole LBP per 1 USD}
        {--source= : Where the figures came from}
        {--verify= : Mark a rate id verified instead of entering one}
        {--by= : Who verified it}
        {--list : Show recent rates}';

    protected $description = 'Record the market and Ministry LBP/USD rates for a day';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->list();
        }

        if ($this->option('verify')) {
            return $this->verify();
        }

        return $this->record();
    }

    private function record(): int
    {
        $date = $this->option('date') ?: Carbon::today()->toDateString();

        $data = [
            'rate_date' => $date,
            'market_rate_lbp_per_usd' => $this->option('market'),
            'mof_rate_lbp_per_usd' => $this->option('mof'),
            'source' => $this->option('source'),
        ];

        $validator = Validator::make($data, [
            'rate_date' => ['required', 'date'],
            'market_rate_lbp_per_usd' => ['nullable', 'integer', 'min:1'],
            'mof_rate_lbp_per_usd' => ['nullable', 'integer', 'min:1'],
            'source' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        if ($data['market_rate_lbp_per_usd'] === null && $data['mof_rate_lbp_per_usd'] === null) {
            $this->components->error('Give at least one of --market or --mof.');

            return self::FAILURE;
        }

        $existing = ExchangeRate::forDate(Carbon::parse($date));

        /*
         * Correcting one rate must not blank the other, and must not silently
         * keep a verification that was given for different numbers.
         */
        $rate = $existing ?? new ExchangeRate;
        $rate->rate_date = $date;
        $rate->source = $data['source'];

        foreach (['market_rate_lbp_per_usd', 'mof_rate_lbp_per_usd'] as $column) {
            if ($data[$column] !== null) {
                $changed = $rate->{$column} !== (int) $data[$column];
                $rate->{$column} = (int) $data[$column];

                if ($changed && $rate->verified_at !== null) {
                    $rate->verified_at = null;
                    $rate->verified_by = null;
                    $this->components->warn('Figures changed — the verification was cleared. Verify again.');
                }
            }
        }

        $rate->save();

        $this->components->info("Rates recorded for {$date}.");
        $this->show($rate);

        if (! $rate->isVerified()) {
            $this->newLine();
            $this->components->warn(
                "Unverified. Nothing can be FILED against this rate until: php artisan exchange:rate --verify={$rate->id} --by=\"Your name\""
            );
        }

        return self::SUCCESS;
    }

    private function verify(): int
    {
        $rate = ExchangeRate::find($this->option('verify'));

        if (! $rate) {
            $this->components->error('No rate with that id.');

            return self::FAILURE;
        }

        $by = $this->option('by') ?: $this->ask('Who is verifying these figures?');

        if (! $by) {
            $this->components->error('Verification records WHO checked the figures. --by is required.');

            return self::FAILURE;
        }

        $rate->update(['verified_at' => now(), 'verified_by' => $by]);

        $this->components->info("Verified by {$by}.");
        $this->show($rate);

        return self::SUCCESS;
    }

    private function list(): int
    {
        $rates = ExchangeRate::query()->orderByDesc('rate_date')->limit(20)->get();

        if ($rates->isEmpty()) {
            $this->components->warn('No rates recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Date', 'Market', 'Ministry', 'Spread', 'Verified'],
            $rates->map(fn (ExchangeRate $r) => [
                $r->id,
                $r->rate_date->toDateString(),
                $r->market_rate_lbp_per_usd === null ? '—' : number_format($r->market_rate_lbp_per_usd),
                $r->mof_rate_lbp_per_usd === null ? '—' : number_format($r->mof_rate_lbp_per_usd),
                $r->spreadBps() === null ? '—' : number_format($r->spreadBps() / 100, 2).'%',
                $r->isVerified() ? $r->verified_by : 'no',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function show(ExchangeRate $rate): void
    {
        $this->components->twoColumnDetail('Date', $rate->rate_date->toDateString());
        $this->components->twoColumnDetail(
            'Market',
            $rate->market_rate_lbp_per_usd === null ? 'not recorded' : number_format($rate->market_rate_lbp_per_usd)
        );
        $this->components->twoColumnDetail(
            'Ministry',
            $rate->mof_rate_lbp_per_usd === null ? 'not recorded' : number_format($rate->mof_rate_lbp_per_usd)
        );

        if ($rate->spreadBps() !== null) {
            $this->components->twoColumnDetail('Spread', number_format($rate->spreadBps() / 100, 2).'%');
        }
    }
}
