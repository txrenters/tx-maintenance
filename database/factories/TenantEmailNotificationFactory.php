<?php

namespace Database\Factories;

use App\Models\TenantEmailNotification;
use App\Models\Tenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantEmailNotification>
 */
class TenantEmailNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenants::factory(),
            'jobber_job_id' => null,
            'type' => 'job_reminder',
            'subject' => fake()->sentence(),
            'body_html' => '<p>'.fake()->sentence().'</p>',
            'body_text' => fake()->sentence(),
            'from_email' => 'service@txhomemp.com',
            'to_email' => fake()->safeEmail(),
            'graph_message_id' => fake()->uuid(),
            'sent_at' => now(),
        ];
    }
}
