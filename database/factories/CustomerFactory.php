<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'phone' => '+961 3 '.fake()->numberBetween(100000, 999999),
            'email' => fake()->safeEmail(),
            'vat_number' => null,
            'address' => null,
            'is_active' => true,
        ];
    }

    public function vatRegistered(string $number = '1234567'): static
    {
        return $this->state(['vat_number' => $number]);
    }
}
