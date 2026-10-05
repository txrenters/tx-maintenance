<?php

namespace Database\Factories;

use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_status_id' => ServiceStatus::query()->first()?->id ?? ServiceStatus::query()->create([
                'name' => 'New',
                'description' => 'New',
            ])->id,
            'work_order_no' => fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            // PropertyWare's own shape, "PORTFOLIO | BUILDING", which its SOAP
            // validates every update against.
            'location' => strtoupper(fake()->lastName()).' | '.strtoupper(str_replace(' ', '', fake()->streetAddress())),
            'status' => 'Open',
            'type' => 'Repair',
            'category' => 'Maintenance',
            'created_date' => now(),
        ];
    }
}
