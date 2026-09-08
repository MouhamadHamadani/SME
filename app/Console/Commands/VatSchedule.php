<?php

namespace App\Console\Commands;

use App\Models\VatRateSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Manage the VAT rate schedule (central — national law, shared by everyone).
 *
 *   php artisan vat:schedule                        list
 *   php artisan vat:schedule --from=2026-01-01 ...  add
 *   php artisan vat:schedule --verify=1 --by=...    mark verified
 *
 * The standard rate defaults to 11%, which has been in force since 2018. The
 * registration threshold has no default — it has moved with the currency and a
 * stale one silently misclassifies who has to register.
 */
class VatSchedule extends Command
{
    protected $signature = 'vat:schedule
        {--from= : Effective from (YYYY-MM-DD) — switches to add mode}
        {--to= : Effective to (YYYY-MM-DD); omit while this is current}
        {--rate=1100 : Standard rate in basis points (1100 = 11%)}
        {--threshold= : Registration threshold, whole LBP}
        {--deadline=20 : Filing deadline, days after the quarter ends}
        {--source= : Where these figures come from}
        {--verify= : Mark the schedule with this id as verified}
        {--by= : Who verified it}';

    protected $description = 'List, add or verify VAT rate schedules';

    public function handle(): int
    {
        if ($this->option('verify')) {
            return $this->verify();
        }

        return $this->option('from') ? $this->add() : $this->list();
    }

    private function list(): int
    {
        $schedules = VatRateSchedule::orderBy('effective_from')->get();

        if ($schedules->isEmpty()) {
            $this->components->warn('No VAT rate schedules. Returns cannot be built until one exists.');
            $this->newLine();
            $this->line('    php artisan vat:schedule --from=2026-01-01 \\');
            $this->line('      --threshold=<LBP> --source="..."');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'From', 'To', 'Rate', 'Threshold', 'Deadline', 'Verified'],
            $schedules->map(fn (VatRateSchedule $s) => [
                $s->id,
                $s->effective_from->toDateString(),
                $s->effective_to?->toDateString() ?? 'current',
                rtrim(rtrim(number_format($s->standard_rate_bps / 100, 2), '0'), '.').'%',
                number_format($s->registration_threshold_lbp).' LBP',
                '+'.$s->filing_deadline_days.' days',
                $s->isVerified() ? 'yes — '.$s->verified_by : 'NO',
            ])->all()
        );

        if ($schedules->every(fn (VatRateSchedule $s) => ! $s->isVerified())) {
            $this->components->warn('No schedule is verified. Draft returns are allowed; filing one is not.');
        }

        return self::SUCCESS;
    }

    private function add(): int
    {
        try {
            $data = validator([
                'effective_from' => $this->option('from'),
                'effective_to' => $this->option('to'),
                'standard_rate_bps' => $this->option('rate'),
                'registration_threshold_lbp' => $this->option('threshold'),
                'filing_deadline_days' => $this->option('deadline'),
                'source_note' => $this->option('source'),
            ], [
                'effective_from' => ['required', 'date_format:Y-m-d', 'unique:vat_rate_schedules,effective_from'],
                'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
                'standard_rate_bps' => ['required', 'integer', 'min:0', 'max:10000'],
                // No default: a zero threshold would read as "everyone must register".
                'registration_threshold_lbp' => ['required', 'integer', 'min:1'],
                'filing_deadline_days' => ['required', 'integer', 'min:1', 'max:365'],
                'source_note' => ['nullable', 'string', 'max:500'],
            ])->validate();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        }

        $previous = VatRateSchedule::whereNull('effective_to')
            ->where('effective_from', '<', $data['effective_from'])
            ->orderByDesc('effective_from')
            ->first();

        if ($previous) {
            $previous->update(['effective_to' => Carbon::parse($data['effective_from'])->subDay()]);
            $this->components->info("Closed schedule #{$previous->id} on {$previous->effective_to->toDateString()}.");
        }

        $schedule = VatRateSchedule::create($data);

        $this->components->info("Added VAT schedule #{$schedule->id} from {$data['effective_from']}.");
        $this->components->warn(
            'Unverified. Returns can be drafted against it but not filed. '
            ."Verify with: php artisan vat:schedule --verify={$schedule->id} --by=\"Your name\""
        );

        return self::SUCCESS;
    }

    private function verify(): int
    {
        $schedule = VatRateSchedule::find($this->option('verify'));

        if (! $schedule) {
            $this->components->error('No schedule with that id.');

            return self::FAILURE;
        }

        if (! $this->option('by')) {
            $this->components->error('Say who checked the figures: --by="Your name".');

            return self::FAILURE;
        }

        $schedule->update(['verified_at' => now(), 'verified_by' => $this->option('by')]);
        $this->components->info("Schedule #{$schedule->id} verified by {$this->option('by')}.");

        return self::SUCCESS;
    }
}
