<?php

namespace Database\Factories;

use App\Models\TenantEmailAttachment;
use App\Models\TenantEmailNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantEmailAttachment>
 */
class TenantEmailAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_email_notification_id' => TenantEmailNotification::factory(),
            'filename' => fake()->word().'.txt',
            'mime' => 'text/plain',
            'size' => 10,
            'path' => 'tenant-email-attachments/'.fake()->uuid().'.txt',
        ];
    }
}
