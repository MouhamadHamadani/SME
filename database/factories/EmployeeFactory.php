<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'nssf_number' => (string) fake()->numberBetween(100000, 999999),
            // 500 USD — a realistic Lebanese SME salary post-crisis.
            'base_salary_minor' => 50_000,
            'base_salary_currency' => Currency::USD,
            'has_dependent_spouse' => false,
            'dependent_children' => 0,
            'is_nssf_registered' => true,
            'started_on' => now()->subYears(2)->startOfMonth(),
            'ended_on' => null,
        ];
    }

    public function paidLbp(int $lira): static
    {
        return $this->state([
            'base_salary_minor' => $lira,
            'base_salary_currency' => Currency::LBP,
        ]);
    }

    public function paidUsd(int $cents): static
    {
        return $this->state([
            'base_salary_minor' => $cents,
            'base_salary_currency' => Currency::USD,
        ]);
    }

    public function undeclared(): static
    {
        return $this->state(['is_nssf_registered' => false, 'nssf_number' => null]);
    }
}
