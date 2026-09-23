<?php

namespace Database\Factories;

use App\Models\Technician;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Technician>
 */
class TechnicianFactory extends Factory
{
    /**
     * Define the model's default state: an active repair technician with an
     * empty profile.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role' => Technician::ROLE_REPAIR,
            'is_active' => true,
            'phone' => null,
            'email' => null,
            'address' => null,
            'specialty' => null,
            'notes' => null,
            'photo_path' => null,
            'photo_content_type' => null,
        ];
    }

    public function inspector(): static
    {
        return $this->state(fn (): array => ['role' => Technician::ROLE_INSPECTOR]);
    }

    public function repair(): static
    {
        return $this->state(fn (): array => ['role' => Technician::ROLE_REPAIR]);
    }

    public function both(): static
    {
        return $this->state(fn (): array => ['role' => Technician::ROLE_BOTH]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
