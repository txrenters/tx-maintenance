<?php

namespace Database\Factories;

use App\Models\OutsideCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutsideCustomer>
 */
class OutsideCustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+1512555'.fake()->unique()->numerify('####'),
            'email' => fake()->unique()->safeEmail(),
            'street' => fake()->streetAddress(),
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '787'.fake()->numerify('##'),
        ];
    }
}
