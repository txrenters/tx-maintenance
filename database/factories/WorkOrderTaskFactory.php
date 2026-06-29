<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderTask>
 */
class WorkOrderTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'assigned_user_id' => User::factory(),
            'description' => fake()->sentence(),
            'due_date' => now()->toDateString(),
            'status' => 'pending',
            'option' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => 'completed']);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => ['status' => 'processing']);
    }
}
