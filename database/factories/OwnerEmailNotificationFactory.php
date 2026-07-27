<?php

namespace Database\Factories;

use App\Models\Owner;
use App\Models\OwnerEmailNotification;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerEmailNotification>
 */
class OwnerEmailNotificationFactory extends Factory
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
            'owner_id' => Owner::factory(),
            'vendor_id' => null,
            'type' => 'vendor_assignment',
            'direction' => 'outbound',
            'subject' => fake()->sentence(),
            'body_html' => '<p>'.fake()->sentence().'</p>',
            'body_text' => fake()->sentence(),
            'from_email' => 'workorders@texasrenters.com',
            'to_email' => fake()->safeEmail(),
            'cc' => [],
            'correlation_tag' => fake()->uuid(),
            'graph_message_id' => fake()->uuid(),
            'sent_at' => now(),
        ];
    }
}
