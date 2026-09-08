<?php

namespace App\Console\Commands;

use App\Models\NssfRateSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Manage the NSSF rate schedule (central — national law, shared by everyone).
 *
 *   php artisan nssf:schedule                       list
 *   php artisan nssf:schedule --from=2026-01-01 ... add
 *   php artisan nssf:schedule --verify=3 --by=...   mark verified
 *
 * The percentage rates default to the long-standing statutory splits
 * (8% / 3% sickness and maternity, 6% family allowances, 8.5% end of service),
 * which have been stable in Lebanese law for decades. The CEILINGS have no
 * defaults and never will: decrees have moved them repeatedly since 2019, and
 * a stale ceiling silently under-contributes for every employee above it.
 */
class NssfSchedule extends Command
{
    protected $signature = 'nssf:schedule
        {--from= : Effective from (YYYY-MM-DD) — switches to add mode}
        {--to= : Effective to (YYYY-MM-DD); omit while this is the current schedule}
        {--sickness-ceiling= : Sickness and maternity ceiling, whole LBP}
        {--family-ceiling= : Family allowances ceiling, whole LBP}
        {--sickness-employer=800 : Employer basis points (800 = 8%)}
        {--sickness-employee=300 : Employee basis points (300 = 3%)}
        {--family-employer=600 : Employer basis points (600 = 6%)}
        {--eosi-employer=850 : Employer basis points (850 = 8.5%), no ceiling by law}
        {--source= : Which decree these figures come from}
        {--verify= : Mark the schedule with this id as verified}
        {--by= : Who verified it}';

    protected $description = 'List, add or verify NSSF rate schedules';

    public function handle(): int
    {
        if ($this->option('verify')) {
            return $this->verify();
        }

        return $this->option('from') ? $this->add() : $this->list();
    }

    private function list(): int
    {
        $schedules = NssfRateSchedule::orderBy('effective_from')->get();

        if ($schedules->isEmpty()) {
            $this->components->warn('No NSSF rate schedules. Payroll cannot run until one exists.');
            $this->line('');
            $this->line('  Add one with the ceilings from the decree in force, e.g.:');
            $this->line('');
            $this->line('    php artisan nssf:schedule --from=2026-01-01 \\');
            $this->line('      --sickness-ceiling=<LBP> --family-ceiling=<LBP> \\');
            $this->line('      --source="Decree ... dated ..."');
            $this->line('');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'From', 'To', 'Sickness', 'Family', 'EOSI', 'Verified'],
            $schedules->map(fn (NssfRateSchedule $s) => [
                $s->id,
                $s->effective_from->toDateString(),
                $s->effective_to?->toDateString() ?? 'current',
                sprintf('%s/%s @ %s', self::pct($s->sickness_employer_bps), self::pct($s->sickness_employee_bps), number_format($s->sickness_ceiling_lbp)),
                sprintf('%s @ %s', self::pct($s->family_employer_bps), number_format($s->family_ceiling_lbp)),
                self::pct($s->eosi_employer_bps).' (uncapped)',
                $s->isVerified() ? 'yes — '.$s->verified_by : 'NO',
            ])->all()
        );

        if ($schedules->every(fn (NssfRateSchedule $s) => ! $s->isVerified())) {
            $this->components->warn(
                'No schedule is verified. Draft runs are allowed; finalising one is not.'
            );
        }

        return self::SUCCESS;
    }

    private function add(): int
    {
        try {
            $data = validator([
                'effective_from' => $this->option('from'),
                'effective_to' => $this->option('to'),
                'sickness_ceiling_lbp' => $this->option('sickness-ceiling'),
                'family_ceiling_lbp' => $this->option('family-ceiling'),
                'sickness_employer_bps' => $this->option('sickness-employer'),
                'sickness_employee_bps' => $this->option('sickness-employee'),
                'family_employer_bps' => $this->option('family-employer'),
                'eosi_employer_bps' => $this->option('eosi-employer'),
                'source_note' => $this->option('source'),
            ], [
                'effective_from' => ['required', 'date_format:Y-m-d', 'unique:nssf_rate_schedules,effective_from'],
                'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
                // No defaults, and no zero: a zero ceiling would cap every
                // contribution to nothing and look like a working payroll.
                'sickness_ceiling_lbp' => ['required', 'integer', 'min:1'],
                'family_ceiling_lbp' => ['required', 'integer', 'min:1'],
                'sickness_employer_bps' => ['required', 'integer', 'min:0', 'max:10000'],
                'sickness_employee_bps' => ['required', 'integer', 'min:0', 'max:10000'],
                'family_employer_bps' => ['required', 'integer', 'min:0', 'max:10000'],
                'eosi_employer_bps' => ['required', 'integer', 'min:0', 'max:10000'],
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

        // Close off the previous open-ended schedule so periods never overlap.
        $previous = NssfRateSchedule::whereNull('effective_to')
            ->where('effective_from', '<', $data['effective_from'])
            ->orderByDesc('effective_from')
            ->first();

        if ($previous) {
            $previous->update([
                'effective_to' => Carbon::parse($data['effective_from'])->subDay(),
            ]);
            $this->components->info("Closed schedule #{$previous->id} on {$previous->effective_to->toDateString()}.");
        }

        $schedule = NssfRateSchedule::create($data);

        $this->components->info("Added NSSF schedule #{$schedule->id} from {$data['effective_from']}.");
        $this->components->warn(
            'Unverified. Payroll can be drafted against it but not finalised. '
            ."Verify with: php artisan nssf:schedule --verify={$schedule->id} --by=\"Your name\""
        );

        return self::SUCCESS;
    }

    private function verify(): int
    {
        $schedule = NssfRateSchedule::find($this->option('verify'));

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

    private static function pct(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2), '0'), '.').'%';
    }
}
