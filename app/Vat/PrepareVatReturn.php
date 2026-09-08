<?php

namespace App\Vat;

use App\Models\VatRateSchedule;
use App\Models\VatReturn;
use App\Models\VatTransaction;
use App\Vat\Exceptions\VatException;
use Illuminate\Support\Facades\DB;

/**
 * Build (or rebuild) one quarter's VAT return from the transactions in it.
 *
 * Re-preparing a draft quarter recomputes it. A finalised quarter is immutable.
 */
class PrepareVatReturn
{
    public function forQuarter(int $year, int $quarter): VatReturn
    {
        if ($quarter < 1 || $quarter > 4) {
            throw VatException::invalidQuarter($quarter);
        }

        $label = sprintf('%04d-Q%d', $year, $quarter);
        $start = VatReturn::periodStart($year, $quarter);
        $end = VatReturn::periodEnd($year, $quarter);

        $existing = VatReturn::where('period_year', $year)->where('period_quarter', $quarter)->first();

        if ($existing?->isFinalised()) {
            throw VatException::alreadyFinalised($label);
        }

        // The rules in force during the period being reported, not today's.
        $schedule = VatRateSchedule::inForceOn($end);

        if (! $schedule) {
            throw VatException::noRateSchedule($label);
        }

        return DB::transaction(function () use ($existing, $year, $quarter, $start, $end, $schedule) {
            $sales = VatTransaction::sales()->inPeriod($start, $end)->get();
            $purchases = VatTransaction::purchases()->inPeriod($start, $end)->get();

            // Exempt sales are outside the tax and stay out of taxable turnover.
            $taxableSales = $sales
                ->filter(fn (VatTransaction $t) => $t->treatment->countsTowardTaxableTurnover())
                ->sum('net_lbp');
            $exemptSales = $sales
                ->reject(fn (VatTransaction $t) => $t->treatment->countsTowardTaxableTurnover())
                ->sum('net_lbp');

            $outputVat = (int) $sales->sum('vat_lbp');

            // Only input VAT the bookkeeper marked recoverable is claimed.
            // Apportionment for a partly-exempt business is not modelled.
            $recoverable = $purchases->filter(fn (VatTransaction $t) => $t->is_recoverable);
            $inputVat = (int) $recoverable->sum('vat_lbp');

            $broughtForward = $this->creditBroughtForward($year, $quarter);

            // Positive = owed to the Ministry. Negative = in credit.
            $netPosition = $outputVat - $inputVat - $broughtForward;

            $return = $existing ?? new VatReturn;

            $return->fill([
                'period_year' => $year,
                'period_quarter' => $quarter,
                'vat_rate_schedule_id' => $schedule->id,
                'standard_rate_bps' => $schedule->standard_rate_bps,
                'filing_deadline_days' => $schedule->filing_deadline_days,
                'due_on' => $end->copy()->addDays($schedule->filing_deadline_days),
                'taxable_sales_lbp' => (int) $taxableSales,
                'exempt_sales_lbp' => (int) $exemptSales,
                'output_vat_lbp' => $outputVat,
                'purchases_lbp' => (int) $purchases->sum('net_lbp'),
                'input_vat_lbp' => $inputVat,
                'credit_brought_forward_lbp' => $broughtForward,
                'net_position_lbp' => $netPosition,
                'payable_lbp' => max(0, $netPosition),
                'credit_carried_forward_lbp' => max(0, -$netPosition),
                'status' => 'draft',
                'finalised_at' => null,
            ])->save();

            return $return->fresh();
        });
    }

    /**
     * Credit left over from the previous quarter.
     *
     * Only a FINALISED return carries forward — a draft can still change, and
     * building on a moving figure would silently alter this period every time
     * the last one was re-prepared.
     */
    private function creditBroughtForward(int $year, int $quarter): int
    {
        [$prevYear, $prevQuarter] = $quarter === 1 ? [$year - 1, 4] : [$year, $quarter - 1];

        return (int) (VatReturn::where('period_year', $prevYear)
            ->where('period_quarter', $prevQuarter)
            ->where('status', 'finalised')
            ->value('credit_carried_forward_lbp') ?? 0);
    }
}
