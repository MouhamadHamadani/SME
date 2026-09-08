<?php

namespace Database\Factories;

use App\Models\VatTransaction;
use App\Support\Currency;
use App\Support\Money;
use App\Vat\VatCalculator;
use App\Vat\VatTreatment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VatTransaction>
 */
class VatTransactionFactory extends Factory
{
    protected $model = VatTransaction::class;

    public function definition(): array
    {
        return [
            'direction' => 'sale',
            'treatment' => VatTreatment::Standard,
            'transacted_on' => now()->toDateString(),
            'description' => fake()->sentence(3),
            'counterparty' => fake()->company(),
            'counterparty_vat_number' => null,
            'amount_minor' => 1_000_000,
            'amount_currency' => Currency::LBP,
            'lbp_per_usd' => null,
            'net_lbp' => 1_000_000,
            'vat_lbp' => 110_000,
            'is_recoverable' => true,
            'vat_return_id' => null,
        ];
    }

    /** A line computed from a net LBP amount at the given rate. */
    public function net(int $lbp, VatTreatment $treatment = VatTreatment::Standard, int $bps = 1100): static
    {
        $amount = (new VatCalculator)->fromNet(Money::lbp($lbp), $treatment, $bps);

        return $this->state([
            'treatment' => $treatment,
            'amount_minor' => $lbp,
            'amount_currency' => Currency::LBP,
            'net_lbp' => $amount->net->minorUnits,
            'vat_lbp' => $amount->vat->minorUnits,
        ]);
    }

    public function sale(): static
    {
        return $this->state(['direction' => 'sale']);
    }

    public function purchase(): static
    {
        return $this->state(['direction' => 'purchase']);
    }

    public function notRecoverable(): static
    {
        return $this->state(['is_recoverable' => false]);
    }

    public function on(string $date): static
    {
        return $this->state(['transacted_on' => $date]);
    }
}
